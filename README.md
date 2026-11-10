# QR Code Fácil

Gerador de QR Codes personalizados, open-source e 100% client-side (HTML + CSS + JavaScript puro).
Nenhum dado digitado sai do navegador; o servidor só entrega arquivos estáticos.

## Funcionalidades

- **Tipos de conteúdo:** Link, Texto, Localização (coordenadas ou link de mapa), Chamada (`tel:`),
  E-mail (`mailto:`), SMS (`SMSTO:`), WhatsApp (`wa.me`), Wi-Fi (`WIFI:T:...;S:...;P:...;;`),
  V-Card 3.0, Pix (BR Code estático com CRC16), Bitcoin (BIP-21) e PayPal.Me.
- **Personalização:** cor de fundo ou fundo transparente, cor dos pontos, cor da moldura e do centro dos olhos,
  formato dos pontos (quadrados, arredondados, pontos, clássico...), formato dos olhos, nível de correção de erro,
  margem e logo central.
- **Pré-visualização em tempo real** e botão **Gerar QRCode**.
- **Download** em PNG (512 a 4096 px) e SVG vetorial.
- Funciona offline: a biblioteca [qr-code-styling](https://github.com/kozakdenys/qr-code-styling) (MIT, v1.9.2)
  fica empacotada em `assets/vendor/`.

## Estrutura

```
qrcode/
├── index.html                  # Interface (HTML5 semântico)
├── assets/
│   ├── css/style.css           # Estilos (Grid/Flexbox, responsivo)
│   ├── js/app.js               # Interface, preview e downloads (módulo ES)
│   ├── js/payloads.js          # Tipos de conteúdo e montagem dos payloads
│   ├── js/icons.js             # Ícones SVG dos cards
│   ├── img/favicon.svg
│   └── vendor/qr-code-styling/ # Biblioteca local + licença
├── deploy.sh                   # Instalação automatizada (Debian 13 + Apache2)
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

O script:

1. confere se está rodando como root;
2. instala `apache2`, `curl`, `git`, `tar` (só o que faltar);
3. habilita os módulos `headers`, `rewrite`, `expires` e `mime`;
4. clona/atualiza o repositório em `/opt/qrcode-src` (ou baixa o tarball, se o git falhar);
5. publica os arquivos web em `/var/www/qrcode` (troca atômica), com dono `www-data`, pastas `755` e arquivos `644`;
6. cria `/etc/apache2/sites-available/qrcode.conf` (VirtualHost com cache de assets e cabeçalhos de segurança/CSP)
   e um alias `/qrcode` para acesso pelo IP;
7. roda `apache2ctl configtest` e reinicia o Apache;
8. mostra as URLs de acesso e como apontar o DNS.

Rodar de novo atualiza para a última versão da `main`.

| Variável | Padrão | Descrição |
|---|---|---|
| `DOMAIN` | `qrcode.local` | ServerName do VirtualHost |
| `APP_DIR` | `/var/www/qrcode` | Pasta publicada |
| `SRC_DIR` | `/opt/qrcode-src` | Cópia do repositório |
| `BRANCH` | `main` | Branch implantada |
| `ENABLE_ALIAS` | `1` | Publica também em `http://<ip>/qrcode/` |
| `DISABLE_DEFAULT_SITE` | `0` | `1` desativa o `000-default` do Apache |

HTTPS depois do DNS apontado:

```bash
sudo apt-get install -y certbot python3-certbot-apache
sudo certbot --apache -d qr.exemplo.com
```

## Desenvolvimento local

Como o JavaScript usa módulos ES, abra via servidor HTTP (não `file://`):

```bash
python3 -m http.server 8080
# http://localhost:8080
```

## Licença

MIT. A biblioteca qr-code-styling é MIT © Denys Kozak.
