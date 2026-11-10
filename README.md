# QR Code Fácil

Gerador de QR Codes personalizados, open-source. Funciona de dois jeitos:

- **Sem conta (rápido):** 100% no navegador (HTML + CSS + JavaScript puro). Nada do que você digita sai
  do seu computador e o QR Code gerado é estático, sem rastreamento.
- **Com conta grátis:** o QR Code de um link pode virar um **link rastreado** (`/r/<código>`): cada
  abertura é contada, o destino pode ser trocado depois de impresso e tudo fica no painel.

## Funcionalidades

- **Tipos de conteúdo:** Link, Texto, Localização (coordenadas ou link de mapa), Chamada (`tel:`),
  E-mail (`mailto:`), SMS (`SMSTO:`), WhatsApp (`wa.me`), Wi-Fi (`WIFI:T:...;S:...;P:...;;`),
  V-Card 3.0, Pix (BR Code estático com CRC16), Bitcoin (BIP-21) e PayPal.Me.
- **Personalização:** cores, fundo transparente, formato dos pontos e dos olhos, correção de erro, margem e logo.
- **Download** em PNG (512 a 4096 px) e SVG vetorial.

### Contas (PHP 8 + SQLite, sem servidor de banco)

- Cadastro, login, "esqueci minha senha" por e-mail, confirmação de e-mail, troca de senha e exclusão da conta.
- **Painel do usuário:** histórico de QR Codes, total de aberturas, gráfico de 30 dias, aberturas por
  dispositivo, sistema, navegador/app, idioma e horário, últimas aberturas e exportação CSV.
- **Links dinâmicos:** editar o destino, pausar/reativar, zerar estatísticas.
- **Painel do administrador:** visão geral, lista/busca de contas, ativar/desativar, promover a admin,
  enviar link de nova senha, encerrar sessões, bloquear links abusivos, excluir contas, registro de segurança
  e configurações (endereço público, cadastros abertos, retenção de dados, **SMTP** com e-mail de teste).

### Segurança

- Senhas com **Argon2id** (`password_hash`), mínimo de 10 caracteres, rehash automático.
- Limite de tentativas: 5 erros por conta / 20 por rede em 15 min; limites também em cadastro, recuperação,
  criação de QR Codes e registro de cliques.
- Recuperação de senha com token aleatório de 256 bits, **uso único**, válido por 1 hora; só o hash SHA-256
  fica no banco. Resposta igual exista ou não a conta. Redefinir encerra todas as sessões.
- Links dos e-mails usam o endereço configurado pelo admin, nunca o cabeçalho `Host` (evita envenenamento).
- Sessões: cookie `__Host-` (com HTTPS), `HttpOnly`, `Secure`, `SameSite=Lax`, ID renovado no login,
  expiração por inatividade (2 h para admins) e versão de sessão para derrubar logins antigos.
- CSRF em todos os formulários e na API + checagem de `Origin`/`Sec-Fetch-Site`.
- SQL só com consultas preparadas (PDO). Escape de HTML em todas as views. CSP sem scripts inline.
- Senha do SMTP cifrada no banco (libsodium `secretbox`) com chave em `/var/lib/qrcode/secret.key` (0600);
  TLS do SMTP sempre verificado e autenticação só com STARTTLS/SSL.
- Dados fora da raiz web (`/var/lib/qrcode`); o Apache só publica `public/` e só executa `app.php`.
- **LGPD:** dos cliques guardamos só IP truncado (/24 IPv4, /48 IPv6), tipo de aparelho, sistema, navegador,
  idioma e um identificador anônimo que muda todo dia. Robôs e pré-visualizações (WhatsApp, Facebook...) não contam.
  Retenção configurável (padrão 365 dias).

## Estrutura

```
qrcode/
├── public/                     # Raiz web (única pasta publicada pelo Apache)
│   ├── index.html              # Gerador (funciona sem conta)
│   ├── app.php                 # Front controller: contas, painel, admin, API e /r/<código>
│   └── assets/                 # CSS, JS (módulos ES) e qr-code-styling empacotado
├── app/                        # Código PHP (fora da web)
│   ├── bootstrap.php, routes.php, cli.php
│   ├── lib/                    # banco, sessões/CSRF, rate limit, SMTP, cifragem, cliques
│   ├── controllers/            # auth, painel, admin, api, redirecionamento
│   └── views/                  # templates
├── deploy.sh                   # Instalação automatizada (Debian 13 + Apache2 + PHP-FPM)
└── LICENSE
```

## Instalação em Debian 13 (Trixie) com Apache2

```bash
curl -sSL https://raw.githubusercontent.com/efbecker/qrcode/main/deploy.sh | sudo bash
```

Com domínio próprio:

```bash
curl -sSL https://raw.githubusercontent.com/efbecker/qrcode/main/deploy.sh | sudo DOMAIN=qr.exemplo.com bash
```

Já instalado? Rode o mesmo comando para atualizar: o domínio configurado antes é reaproveitado e o banco
de dados é preservado. Para criar o primeiro administrador já na instalação, acrescente `ADMIN_EMAIL`:

```bash
curl -sSL https://raw.githubusercontent.com/efbecker/qrcode/main/deploy.sh | sudo ADMIN_EMAIL=voce@exemplo.com bash
```

(a senha é gerada e exibida no final) ou, a qualquer momento:

```bash
sudo qrcode-admin create-admin voce@exemplo.com "Seu Nome"   # cria/promove admin
sudo qrcode-admin reset-password voce@exemplo.com            # nova senha pelo terminal
sudo qrcode-admin list-users
```

Depois entre em `/entrar` → **Administração → Configurações** e preencha o SMTP (clique em
"Enviar e-mail de teste" antes de salvar).

O script:

1. confere se está rodando como root;
2. instala `apache2`, `php-fpm`, `php-sqlite3`, `php-mbstring`, `curl`, `git`, `tar` (só o que faltar);
3. habilita `headers`, `rewrite`, `expires`, `mime`, `proxy_fcgi` e o PHP-FPM, com um `php.ini` endurecido;
4. clona/atualiza o repositório em `/opt/qrcode-src` (ou baixa o tarball, se o git falhar);
5. cria `/var/lib/qrcode` (banco SQLite, chave secreta e sessões; dono `www-data`, `0750`) e `/etc/qrcode/config.php`;
6. publica `public/` e `app/` em `/var/www/qrcode` (troca atômica, código de dono root e só leitura) e migra o banco;
7. cria o VirtualHost com raiz em `/var/www/qrcode/public`, cache, cabeçalhos de segurança/CSP e rotas da aplicação;
   se existir certificado Let's Encrypt para o domínio, configura HTTPS (redireciona o HTTP e ativa HSTS);
8. roda `apache2ctl configtest`, reinicia PHP-FPM e Apache e testa o site e a API;
9. instala o comando `qrcode-admin` e mostra as URLs de acesso.

| Variável | Padrão | Descrição |
|---|---|---|
| `DOMAIN` | o já configurado, ou `qrcode.local` | ServerName do VirtualHost |
| `APP_DIR` | `/var/www/qrcode` | Pasta da aplicação (o Apache publica `APP_DIR/public`) |
| `DATA_DIR` | `/var/lib/qrcode` | Banco SQLite, chave secreta e sessões |
| `ADMIN_EMAIL` | (vazio) | Cria ou promove este e-mail a administrador |
| `SRC_DIR` | `/opt/qrcode-src` | Cópia do repositório |
| `BRANCH` | `main` | Branch implantada |
| `DISABLE_DEFAULT_SITE` | `1` | Desativa o `000-default` para a aplicação responder na raiz (`http://127.0.0.1/`); `0` mantém o site padrão |
| `ENABLE_ALIAS` | `0` | `1` publica também em `http://<ip>/qrcode/` |

HTTPS depois do DNS apontado (importante, pois agora há login e senhas):

```bash
sudo apt-get install -y certbot python3-certbot-apache
sudo certbot --apache -d qr.exemplo.com
# depois rode o deploy.sh de novo: ele detecta o certificado e consolida o HTTPS no qrcode.conf
```

Atrás de proxy reverso/Cloudflare, crie `/etc/qrcode/local.php` (o deploy nunca o sobrescreve) para que os
limites de tentativas e as estatísticas usem o IP real do visitante:

```php
<?php return ['trusted_proxies' => ['IP.DO.PROXY']];
```

## Desenvolvimento local

Requer PHP 8.1+ com `pdo_sqlite`, `sodium` e `mbstring`. Os dados ficam em `./data/` (ignorado pelo git):

```bash
php -S localhost:8080 -t public public/app.php
php app/cli.php create-admin voce@exemplo.com "Seu Nome"
# http://localhost:8080
```

Só o gerador, sem PHP: `cd public && python3 -m http.server 8080` (o site detecta que não há backend
e esconde as funções de conta).

## Licença

MIT. A biblioteca qr-code-styling é MIT © Denys Kozak.
