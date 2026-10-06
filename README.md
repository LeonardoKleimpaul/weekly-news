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
- Migration inicial e testes funcionais do fluxo de usuários.

## Fluxo previsto para as próximas etapas

1. Durante a semana, cada membro escolhe uma sexta-feira e envia uma foto e um texto livre.
2. Com as contribuições prontas, o administrador inicia a apresentação durante a call no Meet.
3. O sistema sorteia a ordem uma única vez, e todos acompanham a mesma contribuição, com nome, foto e texto.
4. O administrador avança pelas contribuições até apresentar todas.
5. O administrador abre a votação. Depois que todos votarem, o resultado fica pronto para ser revelado.
6. A edição é encerrada e preserva contribuições, votos e resultado; os vencedores acumulam pontos no ranking geral.

Ainda precisamos definir o valor dos pontos, o desempate, a regra de voto próprio e a visibilidade do conteúdo antes de implementar essas regras.

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
