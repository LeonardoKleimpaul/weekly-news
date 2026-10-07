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
- Menu lateral fixo no desktop e acessível por botão no celular, com fechamento por toque fora, botão ou tecla Esc.
- Calendário com todas as sextas do ano, agrupadas por mês, navegação entre anos e destaque para a próxima sexta.
- Envio de uma foto e um texto por pessoa e sexta, com prévia, edição e indicação de envio salvo no calendário.
- Sala de apresentação com status dos envios, início pelo administrador, ordem sorteada e passagem sincronizada das histórias.
- Migrations e testes funcionais dos fluxos de usuários, contribuições e apresentações.

## Contribuições de sexta

Em **Sextas**, escolha uma data atual ou futura. A foto e o texto são obrigatórios no primeiro envio. Depois, é possível editar o texto e substituir a foto até o início da apresentação ou 23:59:59 daquela sexta-feira, no horário de Brasília, o que acontecer primeiro. Sextas anteriores ficam disponíveis para consultar o próprio envio, sem permitir alterações.

As fotos aceitam JPG, PNG e WebP, com limite de 5 MiB (aproximadamente 5 MB), e o texto tem até 10 mil caracteres. O conteúdo é texto simples, preservando as quebras de linha. Cada pessoa tem uma contribuição por sexta-feira, garantida também por uma restrição no banco.

Antes da apresentação, cada participante vê apenas o seu conteúdo, inclusive administradores. Na sala, as histórias aparecem uma por vez. Fotos de outras pessoas só ficam acessíveis após serem reveladas. As datas são calculadas pelo calendário; o banco guarda os envios e as apresentações iniciadas.

As fotos ficam fora de `public/`, em `var/uploads/<ambiente>/`, e são servidas por uma rota autenticada que confere o autor ou a revelação na apresentação. O volume Docker `news_uploads` preserva os arquivos quando o container PHP é recriado. O backup deve incluir esse volume e o PostgreSQL.

## Apresentação durante a call

Selecione a sexta e clique em **Preparar apresentação** (administrador) ou **Abrir sala** (membro). A sala mostra quem já enviou, sem expor o conteúdo das histórias. Abra a call no Meet e peça para todos acessarem a sala daquela data.

O administrador pode clicar em **Iniciar apresentação** quando todos os usuários ativos tiverem enviado, incluindo administradores. Contas inativas ficam fora dessa edição. Temporariamente, é permitido iniciar antes da sexta escolhida para testes manuais. A validação original está comentada em `PresentationManager::canStartOn()` e precisa ser reativada após os testes, voltando a permitir o início somente a partir daquela sexta.

A ordem é sorteada uma única vez e salva no PostgreSQL. Todos acompanham o mesmo nome, foto e texto; somente administradores podem clicar em **Próxima história** e **Concluir apresentação**. Atualizar a página mantém a posição. A sala consulta atualizações a cada três segundos e avisa quando perde a conexão. Sem JavaScript, é possível acompanhar atualizando a página manualmente.

O início bloqueia novas alterações nos envios, inclusive formulários abertos antes de começar. Symfony Lock coordena o início, a edição e o avanço; cliques repetidos não pulam histórias. O `LOCK_DSN=flock` padrão atende ao container PHP único atual; múltiplas instâncias devem compartilhar um backend de locks. Não há integração com a API do Meet: a call é aberta por vocês.

## Fluxo previsto para as próximas etapas

1. O administrador abre a votação. Depois que todos votarem, o resultado fica pronto para ser revelado.
2. A edição é encerrada e preserva contribuições, votos e resultado; os vencedores acumulam pontos no ranking geral.

Ainda precisamos definir o valor dos pontos, o desempate e a regra de voto próprio.

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
