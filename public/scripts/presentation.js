(() => {
    const room = document.querySelector('[data-presentation-room]');
    const connection = document.querySelector('[data-room-connection]');
    if (!room || !connection) return;

    let timer;
    let busy = false;
    let submitting = false;
    let sessionEnded = false;
    room.addEventListener('submit', (event) => {
        if (!event.target.matches('[data-presentation-action]')) return;
        if (submitting) {
            event.preventDefault();
            return;
        }
        submitting = true;
        event.target.querySelector('button[type="submit"]').disabled = true;
    });

    const poll = async () => {
        clearTimeout(timer);
        const state = room.querySelector('[data-presentation-state]');
        if (busy || submitting || sessionEnded || document.hidden || state.dataset.finished === 'true') return;
        busy = true;
        try {
            const response = await fetch(room.dataset.stateUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: AbortSignal.timeout(10000),
            });
            if (!response.ok) throw new Error('Resposta indisponível');
            const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = documentFragment.querySelector('[data-presentation-state]');
            if (!next) {
                connection.textContent = 'Sua sessão terminou. Atualize a página para entrar novamente.';
                sessionEnded = true;
                return;
            }
            if (!submitting && state.dataset.revision !== next.dataset.revision) {
                const focusedId = document.activeElement?.id;
                state.replaceWith(next);
                if (focusedId) document.getElementById(focusedId)?.focus({ preventScroll: true });
                connection.textContent = next.dataset.announcement;
            } else {
                connection.textContent = 'Atualização automática ativa.';
            }
        } catch {
            connection.textContent = 'Conexão interrompida. Tentando atualizar novamente…';
        } finally {
            busy = false;
            if (!submitting && !sessionEnded && room.querySelector('[data-presentation-state]').dataset.finished !== 'true') {
                timer = setTimeout(poll, 3000);
            }
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearTimeout(timer);
        else poll();
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) window.location.reload();
    });
    timer = setTimeout(poll, 3000);
})();
