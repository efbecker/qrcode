#!/usr/bin/env bash
# =============================================================================
#  QR Code Fácil · deploy.sh
#  Instalação / atualização automatizada em Debian 13 (Trixie) com Apache2 + PHP-FPM.
#
#  Uso (instalação ou atualização, o script é idempotente):
#    curl -sSL https://raw.githubusercontent.com/efbecker/qrcode/main/deploy.sh | sudo bash
#
#  Com domínio próprio (recomendado em produção):
#    curl -sSL https://raw.githubusercontent.com/efbecker/qrcode/main/deploy.sh | sudo DOMAIN=qr.exemplo.com bash
#
#  Variáveis opcionais (passe antes de "bash", como no exemplo acima):
#    DOMAIN=qr.exemplo.com    ServerName do VirtualHost (padrão: o já configurado, ou qrcode.local)
#    APP_DIR=/var/www/qrcode  Pasta da aplicação (o Apache publica só APP_DIR/public)
#    DATA_DIR=/var/lib/qrcode Banco SQLite, chave secreta e sessões (fora da web)
#    SRC_DIR=/opt/qrcode-src  Cópia do repositório (fonte das atualizações)
#    REPO_URL=...             Repositório Git (padrão: github.com/efbecker/qrcode)
#    BRANCH=main              Branch a implantar
#    DISABLE_DEFAULT_SITE=1   Desativa o 000-default para a aplicação responder na raiz
#    ENABLE_ALIAS=0           1 = publica também em http://<ip-do-servidor>/qrcode/
#    ADMIN_EMAIL=voce@x.com   Cria (ou promove) este e-mail como administrador;
#                             a senha é gerada e exibida no final
# =============================================================================

# Todo o script fica dentro de main(): se o download via curl for interrompido
# no meio, nada é executado pela metade.
main() {
  set -Eeuo pipefail
  umask 022

  # --------------------------------------------------------------- config
  readonly APP_NAME="qrcode"
  readonly REPO_URL="${REPO_URL:-https://github.com/efbecker/qrcode}"
  readonly BRANCH="${BRANCH:-main}"
  readonly APP_DIR="${APP_DIR:-/var/www/qrcode}"
  readonly DATA_DIR="${DATA_DIR:-/var/lib/qrcode}"
  readonly SRC_DIR="${SRC_DIR:-/opt/qrcode-src}"
  readonly ENABLE_ALIAS="${ENABLE_ALIAS:-0}"
  readonly DISABLE_DEFAULT_SITE="${DISABLE_DEFAULT_SITE:-1}"
  readonly ADMIN_EMAIL="${ADMIN_EMAIL:-}"
  readonly TARBALL_URL="${TARBALL_URL:-${REPO_URL%.git}/archive/refs/heads/${BRANCH}.tar.gz}"

  readonly SITE_CONF="/etc/apache2/sites-available/${APP_NAME}.conf"
  readonly ALIAS_CONF="/etc/apache2/conf-available/${APP_NAME}-alias.conf"
  readonly LE_SSL_CONF="/etc/apache2/sites-available/${APP_NAME}-le-ssl.conf"
  readonly CONFIG_DIR="/etc/qrcode"
  readonly CONFIG_FILE="${CONFIG_DIR}/config.php"
  readonly DOC_ROOT="${APP_DIR}/public"
  readonly DEPS=(apache2 curl git tar ca-certificates php-fpm php-cli php-sqlite3 php-mbstring)
  export DEBIAN_FRONTEND=noninteractive

  # Reaproveita o domínio de uma instalação anterior quando DOMAIN não é informado
  local prev_domain=""
  [[ -f "${SITE_CONF}" ]] && prev_domain="$(awk '$1=="ServerName"{print $2; exit}' "${SITE_CONF}" 2>/dev/null || true)"
  readonly DOMAIN="${DOMAIN:-${prev_domain:-qrcode.local}}"

  # --------------------------------------------------------------- saída
  if [[ -t 1 ]]; then
    C_BLUE=$'\e[1;34m'; C_GREEN=$'\e[1;32m'; C_YELLOW=$'\e[1;33m'; C_RED=$'\e[1;31m'; C_RESET=$'\e[0m'
  else
    C_BLUE=''; C_GREEN=''; C_YELLOW=''; C_RED=''; C_RESET=''
  fi
  step() { printf '\n%s==>%s %s\n' "$C_BLUE" "$C_RESET" "$*"; }
  ok()   { printf '    %s✔%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
  warn() { printf '    %s!%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
  die()  { printf '\n%s✖ ERRO:%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }
  trap 'die "falha na linha ${LINENO}: ${BASH_COMMAND}"' ERR

  # --------------------------------------------------------------- 1. privilégios
  step "Verificando privilégios"
  if [[ "${EUID}" -ne 0 ]]; then
    die "execute como root ou com sudo:  curl -sSL <url>/deploy.sh | sudo bash"
  fi
  ok "rodando como root"

  if [[ -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    if [[ "${ID:-}" != "debian" || "${VERSION_ID:-}" != "13" ]]; then
      warn "sistema detectado: ${PRETTY_NAME:-desconhecido}. O script foi feito para Debian 13 (Trixie); continuando assim mesmo."
    else
      ok "${PRETTY_NAME}"
    fi
  fi

  # Valida variáveis que vão parar dentro da configuração do Apache / PHP
  [[ "${DOMAIN}" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || die "DOMAIN inválido: '${DOMAIN}'"
  local d
  for d in "${APP_DIR}" "${SRC_DIR}" "${DATA_DIR}"; do
    [[ "${d}" =~ ^/[A-Za-z0-9._/-]+$ && "${d}" != "/" ]] || die "caminho inválido: '${d}'"
  done
  [[ -z "${ADMIN_EMAIL}" || "${ADMIN_EMAIL}" =~ ^[^@[:space:]\'\"]+@[^@[:space:]\'\"]+\.[^@[:space:]\'\"]+$ ]] || die "ADMIN_EMAIL inválido"

  # --------------------------------------------------------------- 2. dependências
  step "Verificando dependências (${DEPS[*]})"
  local missing=() pkg
  for pkg in "${DEPS[@]}"; do
    dpkg-query -W -f='${Status}' "${pkg}" 2>/dev/null | grep -q "install ok installed" || missing+=("${pkg}")
  done
  if ((${#missing[@]})); then
    ok "instalando: ${missing[*]}"
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends "${missing[@]}" >/dev/null
  fi
  local php_ver
  php_ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  php -m | grep -qi '^pdo_sqlite$' || die "extensão pdo_sqlite não carregada"
  php -m | grep -qi '^sodium$' || die "extensão sodium não carregada"
  ok "dependências presentes (PHP ${php_ver})"

  # --------------------------------------------------------------- 3. Apache + PHP-FPM
  step "Habilitando módulos do Apache e PHP-FPM"
  a2enmod -q headers rewrite expires mime proxy_fcgi setenvif >/dev/null
  a2enconf -q "php${php_ver}-fpm" >/dev/null
  # Endurecimento do PHP (não expõe versão, sessões estritas, limites pequenos)
  cat > "/etc/php/${php_ver}/fpm/conf.d/99-qrcode.ini" <<'EOF'
; Gerado por deploy.sh (QR Code Fácil)
expose_php = Off
display_errors = Off
log_errors = On
session.use_strict_mode = 1
session.cookie_httponly = 1
post_max_size = 2M
upload_max_filesize = 1M
allow_url_include = Off
EOF
  ok "headers, rewrite, expires, mime, proxy_fcgi, php${php_ver}-fpm"

  # --------------------------------------------------------------- 4/5. código da aplicação
  step "Obtendo a aplicação (${REPO_URL}, branch ${BRANCH})"
  mkdir -p "$(dirname "${SRC_DIR}")"
  if [[ -d "${SRC_DIR}/.git" ]] && git -C "${SRC_DIR}" fetch -q --depth 1 origin "${BRANCH}" 2>/dev/null; then
    git -C "${SRC_DIR}" reset -q --hard FETCH_HEAD
    git -C "${SRC_DIR}" clean -qfdx
    ok "repositório atualizado em ${SRC_DIR}"
  elif rm -rf "${SRC_DIR}" && git clone -q --depth 1 --branch "${BRANCH}" "${REPO_URL}" "${SRC_DIR}" 2>/dev/null; then
    ok "repositório clonado em ${SRC_DIR}"
  else
    warn "git indisponível para este repositório; baixando tarball"
    rm -rf "${SRC_DIR}" && mkdir -p "${SRC_DIR}"
    curl -fsSL "${TARBALL_URL}" | tar -xz -C "${SRC_DIR}" --strip-components=1
    ok "tarball extraído em ${SRC_DIR}"
  fi
  [[ -f "${SRC_DIR}/public/index.html" && -f "${SRC_DIR}/app/bootstrap.php" ]] || die "código baixado incompleto (public/ ou app/ ausentes)"
  local version
  version="$(git -C "${SRC_DIR}" rev-parse --short HEAD 2>/dev/null || echo tarball)"

  step "Preparando dados e configuração"
  # Dados fora da raiz web: só o PHP (www-data) lê e grava
  install -d -m 0750 -o www-data -g www-data "${DATA_DIR}"
  install -d -m 0700 -o www-data -g www-data "${DATA_DIR}/sessions"
  # URL pública: https se já existir certificado para o domínio
  local cert_file="" key_file="" scheme="http"
  if [[ -f "${LE_SSL_CONF}" ]]; then
    cert_file="$(awk '$1=="SSLCertificateFile"{print $2; exit}' "${LE_SSL_CONF}" || true)"
    key_file="$(awk '$1=="SSLCertificateKeyFile"{print $2; exit}' "${LE_SSL_CONF}" || true)"
  fi
  if [[ -z "${cert_file}" && -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
    cert_file="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
    key_file="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"
  fi
  if [[ -n "${cert_file}" && -f "${cert_file}" && -f "${key_file}" ]]; then
    scheme="https"
  else
    cert_file=""; key_file=""
  fi
  local base_url="${scheme}://${DOMAIN}"
  [[ "${DOMAIN}" == "qrcode.local" ]] && base_url=""
  install -d -m 0755 "${CONFIG_DIR}"
  cat > "${CONFIG_FILE}.new" <<EOF
<?php
// Gerado por deploy.sh (QR Code Fácil). Será sobrescrito na próxima execução.
// A URL pública pode ser alterada em Admin > Configurações (tem prioridade sobre esta).
return [
    'data_dir' => '${DATA_DIR}',
    'base_url' => '${base_url}',
    'timezone' => 'America/Sao_Paulo',
    // Ajustes permanentes (ex.: 'trusted_proxies' => ['IP.DO.PROXY']) vão em ${CONFIG_DIR}/local.php
];
EOF
  chown root:www-data "${CONFIG_FILE}.new"
  chmod 0640 "${CONFIG_FILE}.new"
  mv "${CONFIG_FILE}.new" "${CONFIG_FILE}"
  ok "dados em ${DATA_DIR} (0750, www-data); config em ${CONFIG_FILE}"

  step "Publicando em ${APP_DIR}"
  # Publica public/ e app/ numa pasta nova e troca de uma vez (sem site quebrado no meio)
  local stage="${APP_DIR}.new" old="${APP_DIR}.old"
  rm -rf "${stage}" "${old}"
  mkdir -p "${stage}"
  tar -C "${SRC_DIR}" --exclude='./data' -cf - ./public ./app ./LICENSE | tar -C "${stage}" -xf -

  # Código pertence ao root (o PHP não consegue alterá-lo); só leitura para www-data
  chown -R root:root "${stage}"
  find "${stage}" -type d -exec chmod 755 {} +
  find "${stage}" -type f -exec chmod 644 {} +

  if [[ -d "${APP_DIR}" ]]; then mv "${APP_DIR}" "${old}"; fi
  mv "${stage}" "${APP_DIR}"
  rm -rf "${old}"
  ok "versão ${version} publicada (raiz web: ${DOC_ROOT})"

  # Banco de dados: cria/migra como www-data
  runuser -u www-data -- php "${APP_DIR}/app/cli.php" migrate >/dev/null
  ok "banco SQLite pronto em ${DATA_DIR}/qrcode.sqlite"

  # Atalho de administração
  cat > /usr/local/bin/qrcode-admin <<EOF
#!/bin/sh
# Gerado por deploy.sh: administração do QR Code Fácil (roda como www-data)
exec runuser -u www-data -- php "${APP_DIR}/app/cli.php" "\$@"
EOF
  chmod 0755 /usr/local/bin/qrcode-admin
  ok "comando qrcode-admin instalado"

  # --------------------------------------------------------------- 6. VirtualHost
  step "Configurando o Apache"

  # Bloco <Directory> compartilhado (VirtualHost, HTTPS e Alias). $1 = RewriteBase
  dir_block() {
    cat <<EOF
    <Directory "${DOC_ROOT}">
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.html

        RewriteEngine On
        RewriteBase $1
        # Bloqueia arquivos/pastas ocultos (.git, .env, ...), exceto validação do Let's Encrypt
        RewriteCond %{REQUEST_URI} !/\\.well-known/acme-challenge/
        RewriteRule "(^|/)\\." - [F,L]
        # Rotas da aplicação (contas, painel, admin, API e links /r/<código>)
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ app.php [L,QSA]

        # Só o front controller pode ser executado como PHP
        <FilesMatch "\\.php\$">
            Require all denied
        </FilesMatch>
        <Files "app.php">
            Require all granted
        </Files>

        # Tipos MIME corretos para módulos ES e SVG
        AddType text/javascript .js .mjs
        AddType image/svg+xml .svg

        # Cabeçalhos de segurança
        Header always set X-Content-Type-Options "nosniff"
        Header always set X-Frame-Options "SAMEORIGIN"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
        Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"
        Header always set Cross-Origin-Opener-Policy "same-origin"
        Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self' data: blob:; font-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'"

        # Cache: assets estáticos por 30 dias; HTML sempre revalidado
        ExpiresActive On
        ExpiresDefault "access plus 0 seconds"
        <FilesMatch "\\.(css|js|mjs|svg|png|jpe?g|webp|ico|woff2?)\$">
            ExpiresDefault "access plus 30 days"
            Header set Cache-Control "public, max-age=2592000"
        </FilesMatch>
        <FilesMatch "\\.html?\$">
            Header set Cache-Control "no-cache"
        </FilesMatch>
    </Directory>
EOF
  }

  local alias_line=""
  [[ "${DOMAIN}" != "qrcode.local" && "${DOMAIN}" != www.* ]] && alias_line="    ServerAlias www.${DOMAIN}"

  {
    echo "# Gerado por deploy.sh (QR Code Fácil). Será sobrescrito na próxima execução."
    echo "<VirtualHost *:80>"
    echo "    ServerName ${DOMAIN}"
    [[ -n "${alias_line}" ]] && echo "${alias_line}"
    echo "    DocumentRoot ${DOC_ROOT}"
    echo "    ServerSignature Off"
    if [[ -n "${cert_file}" ]]; then
      cat <<EOF
    # Certificado encontrado: todo acesso HTTP vai para HTTPS
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [L,R=301]
EOF
    fi
    dir_block "/"
    echo "    ErrorLog \${APACHE_LOG_DIR}/${APP_NAME}_error.log"
    echo "    CustomLog \${APACHE_LOG_DIR}/${APP_NAME}_access.log combined"
    echo "</VirtualHost>"
    if [[ -n "${cert_file}" ]]; then
      echo
      echo "<IfModule mod_ssl.c>"
      echo "<VirtualHost *:443>"
      echo "    ServerName ${DOMAIN}"
      [[ -n "${alias_line}" ]] && echo "${alias_line}"
      echo "    DocumentRoot ${DOC_ROOT}"
      echo "    ServerSignature Off"
      echo "    SSLEngine on"
      echo "    SSLCertificateFile ${cert_file}"
      echo "    SSLCertificateKeyFile ${key_file}"
      [[ -f /etc/letsencrypt/options-ssl-apache.conf ]] && echo "    Include /etc/letsencrypt/options-ssl-apache.conf"
      echo "    Header always set Strict-Transport-Security \"max-age=31536000\""
      dir_block "/"
      echo "    ErrorLog \${APACHE_LOG_DIR}/${APP_NAME}_error.log"
      echo "    CustomLog \${APACHE_LOG_DIR}/${APP_NAME}_access.log combined"
      echo "</VirtualHost>"
      echo "</IfModule>"
    fi
  } > "${SITE_CONF}"
  ok "VirtualHost: ${SITE_CONF}"

  if [[ -n "${cert_file}" ]]; then
    a2enmod -q ssl >/dev/null
    # O HTTPS agora está no qrcode.conf; o arquivo antigo do certbot apontaria para a pasta errada
    if [[ -e "/etc/apache2/sites-enabled/${APP_NAME}-le-ssl.conf" ]]; then
      a2dissite -q "${APP_NAME}-le-ssl" >/dev/null
      ok "HTTPS consolidado no qrcode.conf (qrcode-le-ssl desativado)"
    fi
    ok "HTTPS ativo com ${cert_file}"
  fi

  {
    echo "# Gerado por deploy.sh (QR Code Fácil). Publica a aplicação em /${APP_NAME}/"
    echo "# em qualquer host do servidor (útil para acessar pelo IP sem DNS)."
    echo "Alias /${APP_NAME} \"${DOC_ROOT}\""
    dir_block "/${APP_NAME}/"
  } > "${ALIAS_CONF}"
  ok "Alias /${APP_NAME}: ${ALIAS_CONF}"

  a2ensite -q "${APP_NAME}" >/dev/null
  if [[ "${ENABLE_ALIAS}" == "1" ]]; then
    a2enconf -q "${APP_NAME}-alias" >/dev/null
  else
    a2disconf -q "${APP_NAME}-alias" >/dev/null 2>&1 || true
  fi
  if [[ "${DISABLE_DEFAULT_SITE}" == "1" ]]; then
    # Sem o 000-default, o VirtualHost do QR Code vira o padrão da porta 80
    # e responde na raiz para qualquer endereço (IP, localhost, domínio).
    a2dissite -q 000-default >/dev/null 2>&1 || true
    ok "site padrão 000-default desativado; aplicação servida na raiz /"
    local other
    other="$(find /etc/apache2/sites-enabled -maxdepth 1 -name '*.conf' ! -name "${APP_NAME}.conf" -printf '%f\n' | sort | awk -v me="${APP_NAME}.conf" '$0 < me' | head -n1)"
    [[ -z "${other}" ]] || warn "o site '${other}' carrega antes e pode continuar sendo o padrão por IP; desative-o com a2dissite se necessário"
  fi

  # --------------------------------------------------------------- 7. teste e restart
  step "Testando a configuração e reiniciando Apache e PHP-FPM"
  local test_out
  if ! test_out="$(apache2ctl configtest 2>&1)"; then
    printf '%s\n' "${test_out}" >&2
    die "apache2ctl configtest falhou; o Apache NÃO foi reiniciado"
  fi
  ok "Syntax OK"

  if [[ -d /run/systemd/system ]]; then
    systemctl enable -q "php${php_ver}-fpm" apache2
    systemctl restart "php${php_ver}-fpm"
    systemctl restart apache2
  else
    service "php${php_ver}-fpm" restart >/dev/null   # containers sem systemd
    service apache2 restart >/dev/null
  fi
  ok "php${php_ver}-fpm e apache2 reiniciados"

  local check_url="http://127.0.0.1/" host_hdr=(-H "Host: ${DOMAIN}")
  if [[ -n "${cert_file}" ]]; then
    host_hdr=(--resolve "${DOMAIN}:443:127.0.0.1")
    check_url="https://${DOMAIN}/"
  fi
  if curl -fsS --noproxy "*" "${host_hdr[@]}" "${check_url}" 2>/dev/null | grep -q "QR Code Fácil" && \
     curl -fsS --noproxy "*" "${host_hdr[@]}" "${check_url}api/me" 2>/dev/null | grep -q '"authenticated"'; then
    ok "site e API respondendo em ${check_url}"
  else
    warn "não foi possível validar ${check_url} (verifique /var/log/apache2/${APP_NAME}_error.log)"
  fi

  # --------------------------------------------------------------- 8. administrador
  local admin_msg=""
  if [[ -n "${ADMIN_EMAIL}" ]]; then
    step "Administrador"
    admin_msg="$(printf '\n' | runuser -u www-data -- php "${APP_DIR}/app/cli.php" create-admin "${ADMIN_EMAIL}" "Administrador")"
    ok "${ADMIN_EMAIL}"
  fi
  local admins
  admins="$(runuser -u www-data -- php "${APP_DIR}/app/cli.php" list-users | awk '$3=="admin"' | wc -l)"

  # --------------------------------------------------------------- 9. resumo
  local ip
  ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  ip="${ip:-<ip-do-servidor>}"

  trap - ERR
  cat <<EOF

${C_GREEN}============================================================
  QR Code Fácil instalado com sucesso!  (versão ${version})
============================================================${C_RESET}

  Acesso:
EOF
  [[ -n "${cert_file}" ]] && echo "    https://${DOMAIN}/"
  if [[ "${DISABLE_DEFAULT_SITE}" == "1" ]]; then
    echo "    http://127.0.0.1/"
    echo "    http://${ip}/"
  fi
  [[ "${ENABLE_ALIAS}" == "1" ]] && echo "    http://${ip}/${APP_NAME}/"
  [[ -z "${cert_file}" ]] && echo "    http://${DOMAIN}/   (após apontar o DNS)"
  cat <<EOF

  Aplicação:     ${APP_DIR}  (raiz web: ${DOC_ROOT})
  Dados:         ${DATA_DIR}  (banco SQLite, chave secreta, sessões)
  Configuração:  ${CONFIG_FILE}
  VirtualHost:   ${SITE_CONF}
  Logs:          /var/log/apache2/${APP_NAME}_*.log
EOF
  if [[ -n "${admin_msg}" ]]; then
    printf '\n  %sAdministrador:%s\n' "${C_YELLOW}" "${C_RESET}"
    printf '%s\n' "${admin_msg}" | sed 's/^/    /'
  elif [[ "${admins}" -eq 0 ]]; then
    cat <<EOF

  ${C_YELLOW}Crie o primeiro administrador:${C_RESET}
    sudo qrcode-admin create-admin voce@exemplo.com "Seu Nome"
  Depois entre em /entrar, abra Administração > Configurações e configure o SMTP.
EOF
  fi
  cat <<EOF

  Administração pela linha de comando:  sudo qrcode-admin help
EOF
  if [[ "${DOMAIN}" == "qrcode.local" ]]; then
    cat <<EOF

  Domínio próprio: execute novamente informando DOMAIN:
    curl -sSL https://raw.githubusercontent.com/efbecker/qrcode/main/deploy.sh | sudo DOMAIN=qr.exemplo.com bash
EOF
  fi
  if [[ -z "${cert_file}" ]]; then
    cat <<EOF

  HTTPS (Let's Encrypt), depois do DNS propagado (recomendado: contas e senhas!):
    apt-get install -y certbot python3-certbot-apache
    certbot --apache -d ${DOMAIN}
  Em seguida rode este deploy de novo: ele detecta o certificado e ativa o HTTPS.
EOF
  fi
  cat <<EOF

  Para atualizar, basta rodar o mesmo comando curl novamente
  (o banco de dados em ${DATA_DIR} é preservado).

EOF
}

main "$@"
