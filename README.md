# Weekly News

Projeto Symfony com Docker, FrankenPHP e Caddy, baseado em [Symfony Docker](https://github.com/dunglas/symfony-docker).

O Weekly News guarda as contribuições e os resultados da resenha de sexta-feira do grupo. Frontend e backend ficam juntos no Symfony, com páginas Twig e PostgreSQL via Doctrine.

## Ambiente local

Na primeira instalação, crie o arquivo de configuração local (se `.env` ainda não existir):

```bash
cp .env.example .env
```

Depois, inicie os serviços:

```bash
docker compose build --pull --no-cache
docker compose up --wait
```

Acesse https://localhost e aceite o certificado local gerado pelo Caddy.

O entrypoint aguarda o banco e aplica as migrations existentes. Também é possível aplicar explicitamente:

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

## Primeiro acesso

Crie seu primeiro administrador pelo terminal:

```bash
docker compose exec php php bin/console app:create-admin seu-email@example.com "Seu nome"
```

A senha é solicitada de forma oculta, com 12 a 128 caracteres. Não existe senha padrão nem cadastro público. O comando recusa criar outro administrador quando já existe um ativo.

Entre em https://localhost/login e abra **Usuários** para criar as contas dos participantes. O administrador pode editar nome/e-mail, atribuir o perfil de administrador, ativar/desativar contas e redefinir senhas. Não pode desativar ou remover o próprio perfil de administrador.

Cada participante pode alterar sua senha em **Minha conta**, informando a atual. Mudanças de senha, e-mail, perfil ou status invalidam as outras sessões no próximo acesso. Contas inativas não podem entrar. Os formulários têm proteção CSRF, e o login limita tentativas repetidas.

## Implementado

- Login e logout por sessão.
- Usuários persistidos no PostgreSQL, com e-mail único e senha armazenada por hash.
- Perfis de membro e administrador, gestão de contas e troca de senha.
- Telas responsivas em português, com modos claro e escuro, destaques em verde e troca de tema pelo ícone no topo. A preferência fica salva no navegador; o modo escuro é o padrão.
- Menu lateral recolhível pelo botão dentro da sidebar no desktop, mantendo os ícones de navegação e saída acessíveis, com preferência salva no navegador. No celular, abre pelo botão no topo e fecha por toque fora, botão ou tecla Esc.
- Calendário com todas as sextas do ano, agrupadas por mês, navegação entre anos e destaque para a próxima sexta.
- Envio de uma foto e um texto por pessoa e sexta, com prévia, edição e indicação de envio salvo no calendário.
- Sala de apresentação com status dos envios, início pelo administrador, ordem sorteada e passagem sincronizada das histórias.
- Votação com voto único, bloqueio de voto próprio, revelação pelo administrador e resultado preservado por edição.
- Ranking geral com 1 ponto por voto recebido e posições compartilhadas em caso de empate.
- Transição suave entre histórias, respeitando a preferência de movimento reduzido.
- Migrations e testes funcionais dos fluxos de usuários, contribuições, apresentações e votação.

## Contribuições de sexta

Em **Sextas**, escolha uma data atual ou futura. A foto e o texto são obrigatórios no primeiro envio. Depois, é possível editar o texto e substituir a foto até o início da apresentação ou 23:59:59 daquela sexta-feira, no horário de Brasília, o que acontecer primeiro. Sextas anteriores ficam disponíveis para consultar o próprio envio, sem permitir alterações.

As fotos aceitam JPG, PNG e WebP, com limite de 5 MiB (aproximadamente 5 MB), e o texto tem até 10 mil caracteres. O conteúdo é texto simples, preservando as quebras de linha. Cada pessoa tem uma contribuição por sexta-feira, garantida também por uma restrição no banco.

Antes da apresentação, cada participante vê apenas o seu conteúdo, inclusive administradores. Na sala, as histórias aparecem uma por vez. Fotos de outras pessoas só ficam acessíveis após serem reveladas. As datas são calculadas pelo calendário; o banco guarda os envios e as apresentações iniciadas.

As fotos ficam fora de `public/`, em `var/uploads/<ambiente>/`, e são servidas por uma rota autenticada que confere o autor ou a revelação na apresentação. O volume Docker `news_uploads` preserva os arquivos quando o container PHP é recriado. O backup deve incluir esse volume e o PostgreSQL.

## Apresentação durante a call

Selecione a sexta e clique em **Preparar apresentação** (administrador) ou **Abrir sala** (membro). A sala mostra quem já enviou, sem expor o conteúdo das histórias. Abra a call no Meet e peça para todos acessarem a sala daquela data.

O administrador pode clicar em **Iniciar apresentação** quando todos os usuários ativos tiverem enviado, incluindo administradores. Contas inativas ficam fora dessa edição. Em produção, o início só é permitido a partir daquela sexta, no horário de Brasília. Nos ambientes de desenvolvimento e teste, é permitido antecipar a apresentação para ensaios.

A ordem é sorteada uma única vez e salva no PostgreSQL. Todos acompanham o mesmo nome, foto e texto; somente administradores podem clicar em **Próxima história** e **Concluir apresentação**. Atualizar a página mantém a posição. A sala consulta atualizações a cada três segundos e avisa quando perde a conexão. Sem JavaScript, é possível acompanhar atualizando a página manualmente.

Depois de concluir, qualquer membro pode clicar em **Rever apresentação** na sala daquela sexta. Use **História anterior**, **Próxima história** e **Rever do início** para navegar na ordem original. Cada pessoa revê no próprio ritmo; essa navegação preserva o estado encerrado da apresentação e os envios continuam bloqueados para edição.

O início bloqueia novas alterações nos envios, inclusive formulários abertos antes de começar. Symfony Lock coordena o início, a edição e o avanço; cliques repetidos não pulam histórias. O `LOCK_DSN=flock` padrão atende ao container PHP único atual; múltiplas instâncias devem compartilhar um backend de locks. Não há integração com a API do Meet: a call é aberta por vocês.

## Votação, resultado e ranking

Ao concluir a apresentação, o administrador clica em **Abrir votação**. Cada participante escolhe um nome na lista e clica em **Confirmar voto**. O voto é definitivo, limitado a um por pessoa e edição, com proteção também no banco. O voto em si mesmo é bloqueado; a votação precisa de pelo menos dois participantes.

A lista é formada pelos autores das histórias apresentadas e fica preservada na abertura. Contas criadas depois podem acompanhar, mas não participam dessa votação. Todos dessa lista precisam votar; se uma conta for desativada antes de votar, o administrador deve reativá-la para concluir a edição.

Depois de todos votarem, o administrador pode **Revelar resultado**. A mesma sala mostra o vencedor ou todos os vencedores empatados, a pontuação de cada participante e a data de encerramento. Até a revelação, as quantidades de votos por candidato ficam ocultas, inclusive no ranking.

Cada voto recebido vale **1 ponto**, para todos os participantes que receberam votos. O **Ranking geral**, disponível no menu lateral, soma apenas edições reveladas; revelar novamente não duplica pontos. Empatados compartilham a posição (por exemplo: 1º, 1º, 3º). Contas desativadas que já pontuaram continuam no histórico do ranking.

Contribuições, votos e resultado ficam salvos. A edição encerrada permite consultar o resultado e rever as histórias na ordem original. A sala acompanha a votação e a revelação automaticamente e só para de buscar atualizações após o encerramento.

## Testes

Os testes usam o banco separado `app_test` e não criam contas no banco do site:

```bash
docker compose exec php php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec php php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose exec php php bin/phpunit
```

O GitHub Actions também executa as migrations, os testes e a validação do schema. Além disso, constrói a imagem de produção e testa por HTTP quatro participantes, envios, apresentação, votação, empate, ranking e revisão. Uma instância isolada verifica a persistência após recriar o container e restaurar banco e fotos de um backup; esses testes não usam o banco do site.

## Produção no Railway

O Railway constrói a imagem diretamente do GitHub usando o `Dockerfile`. O HTTPS fica por conta do Railway. Configure a inicialização, as migrations e o healthcheck no painel: [serviços novos não usam mais `railway.json` ou `railway.toml`](https://docs.railway.com/config-as-code).

1. Envie as alterações deste projeto para o GitHub, incluindo o `Dockerfile` e os arquivos de `frankenphp/`.
2. No Railway, crie um **New Project → Deploy from GitHub repo** e selecione `weekly-news`.
3. Dentro do mesmo projeto, adicione **New → Database → PostgreSQL**. Nos exemplos, o serviço se chama `Postgres`; ajuste a referência se escolher outro nome.
4. No serviço **weekly-news**, abra **Variables** e configure:

   | Variável | Valor |
   | --- | --- |
   | `APP_ENV` | `prod` |
   | `APP_DEBUG` | `0` |
   | `APP_SECRET` | Um valor gerado com `openssl rand -hex 32` |
   | `DATABASE_URL` | `${{Postgres.DATABASE_URL}}` |
   | `APP_STORAGE_DIR` | `/app/storage` |
   | `RAILWAY_RUN_UID` | `0` |
   | `PORT` | `8080` |

5. Anexe um **Volume** ao serviço **weekly-news**, com mount path **`/app/storage`**. Esse volume guarda as fotos e as sessões; o PostgreSQL tem seu próprio volume. Mantenha apenas **uma réplica** da aplicação.
6. Em **weekly-news → Settings → Deploy**, preencha:

   | Campo | Valor |
   | --- | --- |
   | Start Command | `/usr/local/bin/start-railway` |
   | Pre-deploy Command | `php bin/console doctrine:migrations:migrate --env=prod --no-interaction --all-or-nothing` |
   | Healthcheck Path | `/login` |
   | Healthcheck Timeout | `300` segundos |

7. Aplique as alterações e aguarde o deploy. Em **Settings → Networking → Generate Domain**, gere o endereço público e selecione a porta **8080**, correspondente à variável `PORT`.

O comando de inicialização precisa estar preenchido. O comando padrão da imagem atende à instalação em VPS e tenta gerenciar certificados; `/usr/local/bin/start-railway` usa HTTP na porta `PORT`, atrás do HTTPS fornecido pelo Railway.

O primeiro build pode falhar enquanto o banco e as variáveis ainda não foram configurados; depois de concluir a configuração, faça um novo deploy. Não é necessário configurar Mercure, certificados ou executar `bin/deploy` no Railway. A apresentação continua sincronizada pelas consultas periódicas existentes.

`RAILWAY_RUN_UID=0` permite preparar as permissões do volume na inicialização; o servidor então passa a executar como `www-data`. `APP_STORAGE_DIR` deve ficar nas variáveis do serviço, para os comandos de console usarem o mesmo local que o servidor. O cache permanece fora do volume, evitando reutilizar o código compilado de um deploy anterior.

### Primeiro administrador

Instale a [CLI do Railway](https://docs.railway.com/guides/cli). Na pasta do projeto, faça login, selecione o projeto/ambiente e entre no container da aplicação:

```bash
railway login
railway link
railway ssh --service weekly-news
```

Na sessão aberta, execute:

```bash
cd /app
php bin/console app:create-admin seu-email@example.com "Seu nome"
```

Informe a senha solicitada e entre no endereço gerado pelo Railway. Se o serviço tiver outro nome, substitua `weekly-news` no comando. Use `railway ssh` para acessar o banco pela rede privada do projeto.

### Custos e backups

Consulte os [preços atuais do Railway](https://railway.com/pricing). O trial oferece US$ 5 em créditos por até 30 dias. O Hobby tem cobrança mínima de US$ 5/mês, incluindo esse valor em consumo; uso excedente é cobrado à parte. Esta configuração precisa de dois volumes: um para o banco e outro para a aplicação. O trial comporta essa estrutura, mas o plano Free após o trial permite apenas um volume por projeto.

Configure backups dos **dois volumes** no Railway e mantenha cópias externas dos dados importantes. Os scripts `bin/backup`, `bin/deploy` e a rotina systemd descritos abaixo são específicos da instalação com Docker Compose em VPS. Backups não são ativados por este repositório.

### Verificação local do ambiente Railway

O teste usa um PostgreSQL isolado, um volume inicialmente pertencente a root e um proxy HTTPS na porta local 18444. Reaproveita os mesmos fluxos HTTP de produção e verifica fotos e sessões após recriar a aplicação:

```bash
docker build --target frankenphp_prod -t weekly-news-railway-check:local .
docker compose -p weekly-news-railway-check -f tests/Deployment/railway.compose.yaml run --rm -T migrations
docker compose -p weekly-news-railway-check -f tests/Deployment/railway.compose.yaml up -d --wait app proxy
docker compose -p weekly-news-railway-check -f tests/Deployment/railway.compose.yaml exec -T --user www-data app php < tests/Deployment/fixtures.php > /tmp/railway-fixtures.json
DEPLOYMENT_TEST_BASE_URL=https://localhost:18444 python3 tests/Deployment/smoke.py /tmp/railway-fixtures.json
docker compose -p weekly-news-railway-check -f tests/Deployment/railway.compose.yaml up -d --wait --force-recreate app
DEPLOYMENT_TEST_BASE_URL=https://localhost:18444 python3 tests/Deployment/smoke.py /tmp/railway-fixtures.json --verify
docker compose -p weekly-news-railway-check -f tests/Deployment/railway.compose.yaml down --volumes
```

O GitHub Actions também executa essa verificação antes de publicar a imagem.

## Produção em VPS

A configuração usa uma instância PHP e PostgreSQL, com HTTPS automático pelo Caddy. O PHP roda sem Xdebug e dependências de desenvolvimento, com debug desativado. Banco, fotos, sessões e certificados ficam em volumes persistentes. O PostgreSQL não publica uma porta externa.

A imagem é construída no GitHub Actions, fora da VPS. Após os testes passarem, um push na branch `main` publica `ghcr.io/<dono>/<repositorio>:sha-<commit>`; o endereço aparece no resumo do workflow. Pull requests e execuções manuais testam a imagem sem publicá-la. As permissões do repositório precisam permitir escrita em GitHub Packages.

### Preparar a VPS

Instale Docker Engine e o plugin Docker Compose, configure acesso SSH por chave e aponte o registro DNS do domínio para a VPS. Libere SSH e as portas 80/TCP e 443/TCP; 443/UDP é opcional para HTTP/3. Não exponha PostgreSQL. Os exemplos usam o checkout do projeto em `/srv/weekly-news`.

Para uma VPS de 512 MB, a configuração limita PHP/Caddy a 256 MB e PostgreSQL a 128 MB, com um worker PHP e caches menores. Configure também aproximadamente 1 GB de swap no host. A imagem deve ser baixada pronta; evite builds na VPS. Esse perfil é destinado ao grupo pequeno atual e precisa ser validado na VPS real, incluindo o consumo do sistema operacional.

Na pasta do projeto:

```bash
cp .env.production.example .env.production
chmod 600 .env.production
```

Edite `SERVER_NAME` com o domínio, `APP_IMAGE` com a imagem do commit publicado e preencha `APP_SECRET`, `POSTGRES_PASSWORD` e `CADDY_MERCURE_JWT_SECRET` com três valores diferentes. Gere cada um com `openssl rand -hex 32`. O formato hexadecimal evita caracteres que precisariam de escape na URL de conexão. Guarde o arquivo em local seguro; ele não é versionado nem incluído na imagem ou nos backups.

Se o pacote GHCR for privado, faça `docker login ghcr.io` com um token que tenha acesso de leitura ao pacote. Para pacote público, o download dispensa login.

### Primeiro deploy e atualizações

Use o checkout correspondente ao commit da imagem, para manter Docker Compose e scripts compatíveis. Execute:

```bash
bin/deploy ghcr.io/<dono>/<repositorio>:sha-<commit>
```

O script baixa as imagens, sobe o banco, pausa o PHP, faz backup de banco e fotos, aplica migrations, valida o schema e inicia o PHP. Só informa sucesso depois que a página de login responde ao healthcheck, e então atualiza `APP_IMAGE` no arquivo de produção. As migrations não rodam automaticamente em cada inicialização de produção. O deploy tem uma breve indisponibilidade; não execute deploy e backup ao mesmo tempo.

No primeiro acesso, crie o administrador:

```bash
docker compose --env-file .env.production -f compose.yaml -f compose.prod.yaml exec php php bin/console app:create-admin seu-email@example.com "Seu nome"
```

O CSS e o JavaScript já estão em `public/` e entram na imagem, sem etapa de compilação. Comandos de produção devem sempre informar os dois arquivos acima: isso evita carregar `compose.override.yaml`, que configura desenvolvimento.

Para conferir serviços e logs:

```bash
docker compose --env-file .env.production -f compose.yaml -f compose.prod.yaml ps
docker compose --env-file .env.production -f compose.yaml -f compose.prod.yaml logs --tail 100
```

### Backups

```bash
bin/backup
```

Cada execução cria uma pasta privada em `backups/`, com `database.dump`, `uploads.tar.gz` e `SHA256SUMS`. O PHP fica pausado enquanto banco e fotos são copiados, para que ambos representem o mesmo momento. Se estava rodando, o script o reinicia ao terminar, inclusive em caso de erro. Uma pasta sem `SHA256SUMS` indica backup incompleto e não deve ser usada para restauração.

Há uma rotina systemd preparada para executar diariamente por volta das 03h de Brasília. Para ativá-la na VPS, com o projeto em `/srv/weekly-news`:

```bash
sudo cp ops/weekly-news-backup.service ops/weekly-news-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now weekly-news-backup.timer
sudo systemctl list-timers weekly-news-backup.timer
```

Se usar outra pasta, ajuste `WorkingDirectory` e `ExecStart` no serviço antes de instalar. Confira falhas com `sudo journalctl -u weekly-news-backup.service`. O script também aceita caminhos: `bin/backup /caminho/.env.production /caminho/backups`.

Os backups locais ainda precisam de cópia automática para outro servidor ou armazenamento e uma política de retenção. Até configurar isso, copie regularmente as pastas completas para fora da VPS e acompanhe o espaço livre. Preserve também as credenciais de produção separadamente; sessões e certificados não fazem parte desses arquivos de backup.

### Restaurar ou recuperar um deploy

Para restaurar, escolha o backup correto e confira os hashes. **Os comandos abaixo substituem os dados do banco pelos do backup**. Execute na pasta do projeto, durante manutenção:

```bash
backup_path=/caminho/do/backup
(cd "$backup_path" && sha256sum --check SHA256SUMS)
docker compose --env-file .env.production -f compose.yaml -f compose.prod.yaml stop php
docker compose --env-file .env.production -f compose.yaml -f compose.prod.yaml exec -T database sh -c 'exec pg_restore --clean --if-exists --exit-on-error -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < "$backup_path/database.dump"
docker compose --env-file .env.production -f compose.yaml -f compose.prod.yaml run --rm -T --no-deps --entrypoint tar php -xzf - -C /app/var/uploads < "$backup_path/uploads.tar.gz"
docker compose --env-file .env.production -f compose.yaml -f compose.prod.yaml up -d --wait --no-build php
```

A extração recupera as fotos referenciadas pelo banco; arquivos posteriores ao backup podem permanecer sem referência. Em um servidor novo, prepare primeiro a configuração, as imagens e o serviço `database`. Use a imagem compatível com o schema do backup; depois faça o deploy das versões seguintes normalmente.

Se um deploy falhar, ele informa a imagem anterior e mantém o backup criado antes das migrations. Investigue os logs antes de reiniciar. Quando o schema continua compatível com a versão anterior, é possível voltar apenas a imagem:

```bash
APP_IMAGE=ghcr.io/<dono>/<repositorio>:sha-<commit-anterior> docker compose --env-file .env.production -f compose.yaml -f compose.prod.yaml up -d --wait --no-build php
```

A última imagem bem-sucedida fica em `.deploy/current-image`, e a anterior em `.deploy/previous-image`. Depois de recuperar a versão anterior, ajuste também `APP_IMAGE` em `.env.production`. Se as migrations alteraram o schema de forma incompatível, restaure o backup e use a imagem correspondente. Não há reversão automática de migrations. Evite `down --volumes` em produção: isso remove banco, fotos, sessões e certificados.

## Parar o ambiente

Para parar os containers:

```bash
docker compose down --remove-orphans
```

## Xdebug

Veja o [guia de configuração do Xdebug](docs/xdebug.md).

## Créditos

Template Symfony Docker sob licença MIT, criado por Kévin Dunglas, com manutenção de Maxime Helias e patrocínio de Les-Tilleuls.coop.
