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

O administrador pode clicar em **Iniciar apresentação** quando todos os usuários ativos tiverem enviado, incluindo administradores. Contas inativas ficam fora dessa edição. Temporariamente, é permitido iniciar antes da sexta escolhida para testes manuais. A validação original está comentada em `PresentationManager::canStartOn()` e precisa ser reativada após os testes, voltando a permitir o início somente a partir daquela sexta.

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

O GitHub Actions também executa as migrations, os testes e a validação do schema.

## Parar o ambiente

Para parar os containers:

```bash
docker compose down --remove-orphans
```

## Xdebug

Veja o [guia de configuração do Xdebug](docs/xdebug.md).

## Créditos

Template Symfony Docker sob licença MIT, criado por Kévin Dunglas, com manutenção de Maxime Helias e patrocínio de Les-Tilleuls.coop.
