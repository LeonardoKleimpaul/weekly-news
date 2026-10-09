"""Exercise the built production image over HTTP using isolated fixtures."""

import base64
import concurrent.futures
import http.cookiejar
import json
import os
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser


class Forms(HTMLParser):
    """Collect forms, buttons and private photo paths from an HTML page."""

    def __init__(self, html):
        super().__init__()
        self.forms = []
        self.buttons = {}
        self.photos = []
        self.current = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "img" and attrs.get("src", "").startswith("/envios/"):
            self.photos.append(attrs["src"])
        if tag == "button" and attrs.get("id"):
            self.buttons[attrs["id"]] = attrs
        if tag == "form":
            self.current = {"action": attrs.get("action", ""), "fields": {}}
            self.forms.append(self.current)
        elif tag == "input" and self.current is not None and attrs.get("name"):
            if attrs.get("type") not in ["radio", "checkbox", "file"]:
                self.current["fields"][attrs["name"]] = attrs.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form":
            self.current = None

    def action(self, suffix):
        """Find a form by its action suffix."""
        return next(
            form for form in self.forms if form["action"].endswith(suffix)
        )


class Client:
    """Maintain a participant's HTTPS session across requests and test runs."""

    def __init__(self, base, cookie_file, reuse_session=False):
        self.base = base
        self.cookies = http.cookiejar.MozillaCookieJar(cookie_file)
        self.reused_session = reuse_session and os.path.exists(cookie_file)
        if self.reused_session:
            self.cookies.load(ignore_discard=True, ignore_expires=True)
        # Only the isolated localhost stack uses this private CA.
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies),
            urllib.request.HTTPSHandler(
                context=ssl._create_unverified_context()
            ),
        )

    def request(self, path, fields=None, upload=False):
        """Send a request, optionally uploading a real PNG with the form."""
        data = None
        headers = {}
        if fields is not None:
            if upload:
                boundary = "weeklynews-production-smoke"
                parts = []
                for name, value in fields.items():
                    parts.append(
                        (
                            f"--{boundary}\r\n"
                            "Content-Disposition: form-data; "
                            f'name="{name}"\r\n'
                            f"\r\n{value}\r\n"
                        ).encode()
                    )
                png = base64.b64decode(
                    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQ"
                    "VR42mP8/x8AAwMCAO+j7S8AAAAASUVORK5CYII="
                )
                parts.append(
                    (
                        f"--{boundary}\r\n"
                        "Content-Disposition: form-data; "
                        'name="submission[photo]"; filename="story.png"\r\n'
                        "Content-Type: image/png\r\n\r\n"
                    ).encode()
                    + png
                    + b"\r\n"
                )
                parts.append(f"--{boundary}--\r\n".encode())
                data = b"".join(parts)
                headers["Content-Type"] = (
                    f"multipart/form-data; boundary={boundary}"
                )
            else:
                data = urllib.parse.urlencode(fields).encode()
        request = urllib.request.Request(
            urllib.parse.urljoin(self.base, path), data=data, headers=headers
        )
        try:
            with self.opener.open(request, timeout=20) as response:
                return response.status, response.read(), response.headers
        except urllib.error.HTTPError as error:
            return error.code, error.read(), error.headers

    def html(self, path, fields=None, upload=False):
        """Require a successful response and decode its HTML."""
        status, body, headers = self.request(path, fields, upload)
        assert status == 200, (
            path,
            status,
            body.decode(errors="replace")[:500],
        )
        return body.decode(), headers

    def submit(self, form, fallback, extra=None):
        """Submit the original hidden fields, including the CSRF token."""
        return self.html(
            form["action"] or fallback, {**form["fields"], **(extra or {})}
        )[0]


def authenticate(base, fixtures, fixture_path, reuse_sessions):
    """Log in four participants or reuse their saved session cookies."""
    clients = [
        Client(base, fixture_path + f".{index}.cookies", reuse_sessions)
        for index, _ in enumerate(fixtures["users"])
    ]
    for client, user in zip(clients, fixtures["users"]):
        if not client.reused_session:
            html, _ = client.html("/login")
            form = Forms(html).action("/login")
            client.submit(
                form,
                "/login",
                {"email": user["email"], "password": "Production-smoke-123"},
            )
        assert any(cookie.secure for cookie in client.cookies), (
            "Session cookies must use HTTPS."
        )
        html, _ = client.html("/ranking")
        assert "data-collapse-menu" in html
        client.cookies.save(ignore_discard=True, ignore_expires=True)
        os.chmod(client.cookies.filename, 0o600)
    return clients


def verify_persistence(clients, fixtures):
    """Verify saved results and uploads after recreation or restoration."""
    for client in clients:
        html, _ = client.html("/ranking")
        assert html.count('data-points="1"') == 4
        html, _ = client.html("/sextas/" + fixtures["past"] + "/apresentacao")
        assert "Esta sexta teve empate!" in html
        html, _ = client.html("/sextas/" + fixtures["future"])
        assert "Upload em produção" in html
        photos = Forms(html).photos
        assert photos and client.request(photos[0])[0] == 200
    print(
        "Production persistence/restore: sessions, contributions, photos, "
        "votes and tied ranking preserved."
    )


def submit_stories(clients, future):
    """Upload four stories and ensure future presentations are blocked."""
    for index, client in enumerate(clients):
        path = "/sextas/" + future
        html, _ = client.html(path)
        form = next(
            form
            for form in Forms(html).forms
            if "submission[_token]" in form["fields"]
        )
        html, _ = client.html(
            form["action"] or path,
            {
                **form["fields"],
                "submission[text]": "Upload em produção " + str(index),
            },
            upload=True,
        )
        assert "Sua contribuição está salva" in html
    future_room = "/sextas/" + future + "/apresentacao"
    html, _ = clients[0].html(future_room)
    future_forms = Forms(html)
    assert "disabled" in future_forms.buttons["presentation-start"], (
        "Production must reject early presentations."
    )
    html = clients[0].submit(future_forms.action("/iniciar"), future_room)
    assert (
        "A apresentação pode começar a partir da sexta-feira escolhida" in html
    )


def present(clients, room):
    """Show all stories while other participants poll and access the photos."""
    admin = clients[0]
    html, _ = admin.html(room)
    html = admin.submit(Forms(html).action("/iniciar"), room)
    for position in range(4):
        assert "História " + str(position + 1) + " de 4" in html
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
            results = list(
                executor.map(
                    lambda client: client.html(room + "/estado")[0], clients
                )
            )
        assert all('data-phase="presenting"' in result for result in results)
        photo_paths = Forms(html).photos
        assert photo_paths
        assert clients[1].request(photo_paths[0])[0] == 200
        html = admin.submit(Forms(html).action("/avancar"), room)


def vote(clients, fixtures, room):
    """Cast one vote each, rejecting self-votes and duplicate submissions."""
    html, _ = clients[0].html(room)
    clients[0].submit(Forms(html).action("/votacao/abrir"), room)
    for index, client in enumerate(clients):
        html, _ = client.html(room)
        form = Forms(html).action("/votar")
        own = fixtures["users"][index]["id"]
        assert f'value="{own}"' not in html, "Own vote must be excluded."
        candidate = fixtures["users"][(index + 1) % 4]["id"]
        html = client.submit(form, room, {"vote[candidate]": candidate})
        assert "data-vote-form" not in html
        html = client.submit(form, room, {"vote[candidate]": candidate})
        assert "já foi confirmado" in html
        ranking, _ = client.html("/ranking")
        assert ranking.count('data-points="0"') == 4, (
            "Ranking must not spoil an unrevealed vote."
        )


def reveal_and_poll(clients, room):
    """Reveal a four-way tie, verify replay and exercise parallel polling."""
    html, _ = clients[0].html(room)
    html = clients[0].submit(Forms(html).action("/resultado/revelar"), room)
    assert "Esta sexta teve empate!" in html
    assert 'data-closed="true"' in html
    for client in clients:
        html, _ = client.html("/ranking")
        assert html.count('data-points="1"') == 4
        html, _ = client.html(room + "/rever")
        assert "História 1 de 4" in html

    # Exercise the single-worker configuration with repeated parallel polling.
    def poll(client):
        for _ in range(15):
            client.html(room + "/estado")
            client.html("/ranking")

    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
        list(executor.map(poll, clients))


def main():
    """Run only against the isolated production stack on localhost."""
    base = os.environ.get(
        "DEPLOYMENT_TEST_BASE_URL", "https://localhost:18443"
    )
    assert urllib.parse.urlparse(base).hostname in [
        "localhost",
        "127.0.0.1",
    ], "Only the isolated localhost stack may be tested."
    with open(sys.argv[1], encoding="utf-8") as fixture_file:
        fixtures = json.load(fixture_file)
    reuse_sessions = "--verify" in sys.argv
    clients = authenticate(base, fixtures, sys.argv[1], reuse_sessions)
    if reuse_sessions:
        verify_persistence(clients, fixtures)
        return
    submit_stories(clients, fixtures["future"])
    room = "/sextas/" + fixtures["past"] + "/apresentacao"
    present(clients, room)
    vote(clients, fixtures, room)
    reveal_and_poll(clients, room)
    print(
        "Production HTTP: 4 logins, uploads, date validation, concurrent "
        "presentation, unique votes, tied ranking and replay passed."
    )


if __name__ == "__main__":
    main()
