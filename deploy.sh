#!/usr/bin/env bash
# =============================================================================
#  QR Code Fácil · deploy.sh
#  Instalação / atualização automatizada em Debian 13 (Trixie) com Apache2.
#
#  Uso (instalação ou atualização, o script é idempotente):
#    curl -sSL https://raw.githubusercontent.com/efbecker/qrcode/main/deploy.sh | sudo bash
#
#  Com domínio próprio (recomendado em produção):
#    curl -sSL https://raw.githubusercontent.com/efbecker/qrcode/main/deploy.sh | sudo DOMAIN=qr.exemplo.com bash
#
#  Variáveis opcionais (passe antes de "bash", como no exemplo acima):
#    DOMAIN=qr.exemplo.com    ServerName do VirtualHost (padrão: qrcode.local)
#    APP_DIR=/var/www/qrcode  Diretório publicado pelo Apache
#    SRC_DIR=/opt/qrcode-src  Cópia do repositório (fonte das atualizações)
#    REPO_URL=...             Repositório Git (padrão: github.com/efbecker/qrcode)
#    BRANCH=main              Branch a implantar
#    DISABLE_DEFAULT_SITE=1   Desativa o 000-default para a aplicação responder na raiz
#                             (http://127.0.0.1/). Use 0 para manter o site padrão.
#    ENABLE_ALIAS=0           1 = publica também em http://<ip-do-servidor>/qrcode/
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
  readonly SRC_DIR="${SRC_DIR:-/opt/qrcode-src}"
  readonly DOMAIN="${DOMAIN:-qrcode.local}"
  readonly ENABLE_ALIAS="${ENABLE_ALIAS:-0}"
  readonly DISABLE_DEFAULT_SITE="${DISABLE_DEFAULT_SITE:-1}"
  readonly TARBALL_URL="${TARBALL_URL:-${REPO_URL%.git}/archive/refs/heads/${BRANCH}.tar.gz}"

  readonly SITE_CONF="/etc/apache2/sites-available/${APP_NAME}.conf"
  readonly ALIAS_CONF="/etc/apache2/conf-available/${APP_NAME}-alias.conf"
  readonly DEPS=(apache2 curl git tar ca-certificates)
  export DEBIAN_FRONTEND=noninteractive

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

  # Valida variáveis que vão parar dentro da configuração do Apache
  [[ "${DOMAIN}" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || die "DOMAIN inválido: '${DOMAIN}'"
  [[ "${APP_DIR}" =~ ^/[A-Za-z0-9._/-]+$ && "${APP_DIR}" != "/" ]] || die "APP_DIR inválido: '${APP_DIR}'"
  [[ "${SRC_DIR}" =~ ^/[A-Za-z0-9._/-]+$ && "${SRC_DIR}" != "/" ]] || die "SRC_DIR inválido: '${SRC_DIR}'"

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
  ok "dependências presentes"

  # --------------------------------------------------------------- 3. módulos do Apache
  step "Habilitando módulos do Apache"
  a2enmod -q headers rewrite expires mime >/dev/null
  ok "headers, rewrite, expires, mime"

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
  [[ -f "${SRC_DIR}/index.html" ]] || die "index.html não encontrado no código baixado"
  local version
  version="$(git -C "${SRC_DIR}" rev-parse --short HEAD 2>/dev/null || echo tarball)"

  step "Publicando em ${APP_DIR}"
  # Publica só os arquivos web, numa pasta nova, e troca de uma vez (sem site quebrado no meio)
  local stage="${APP_DIR}.new" old="${APP_DIR}.old"
  rm -rf "${stage}" "${old}"
  mkdir -p "${stage}"
  tar -C "${SRC_DIR}" \
      --exclude='.git' --exclude='.github' --exclude='.gitattributes' --exclude='.gitignore' \
      --exclude='deploy.sh' --exclude='README.md' \
      -cf - . | tar -C "${stage}" -xf -

  # Permissões: dono www-data; diretórios 755 e arquivos 644 (nada executável na web)
  chown -R www-data:www-data "${stage}"
  find "${stage}" -type d -exec chmod 755 {} +
  find "${stage}" -type f -exec chmod 644 {} +

  if [[ -d "${APP_DIR}" ]]; then mv "${APP_DIR}" "${old}"; fi
  mv "${stage}" "${APP_DIR}"
  rm -rf "${old}"
  ok "versão ${version} publicada"

  # --------------------------------------------------------------- 6. VirtualHost
  step "Configurando o Apache"

  # Bloco compartilhado entre o VirtualHost e o Alias /qrcode
  local dir_block
  dir_block="$(cat <<EOF
    <Directory "${APP_DIR}">
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.html

        # Bloqueia arquivos/pastas ocultos (.git, .env, ...)
        RewriteEngine On
        RewriteRule "(^|/)\\." - [F,L]

        # Tipos MIME corretos para módulos ES e SVG
        AddType text/javascript .js .mjs
        AddType image/svg+xml .svg

        # Cabeçalhos de segurança básicos
        Header always set X-Content-Type-Options "nosniff"
        Header always set X-Frame-Options "SAMEORIGIN"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
        Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"
        Header always set Cross-Origin-Opener-Policy "same-origin"
        Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self' data: blob:; font-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'"

        # Cache: assets estáticos por 30 dias; HTML sempre revalidado
        ExpiresActive On
        ExpiresDefault "access plus 0 seconds"
        <FilesMatch "\\.(css|js|mjs|svg|png|jpe?g|webp|ico|woff2?)$">
            ExpiresDefault "access plus 30 days"
            Header set Cache-Control "public, max-age=2592000"
        </FilesMatch>
        <FilesMatch "\\.html?$">
            Header set Cache-Control "no-cache"
        </FilesMatch>
    </Directory>
EOF
)"

  local alias_line=""
  [[ "${DOMAIN}" != "qrcode.local" && "${DOMAIN}" != www.* ]] && alias_line="    ServerAlias www.${DOMAIN}"

  cat > "${SITE_CONF}" <<EOF
# Gerado por deploy.sh (QR Code Fácil). Será sobrescrito na próxima execução.
<VirtualHost *:80>
    ServerName ${DOMAIN}
${alias_line}
    DocumentRoot ${APP_DIR}
    ServerSignature Off

${dir_block}

    ErrorLog \${APACHE_LOG_DIR}/${APP_NAME}_error.log
    CustomLog \${APACHE_LOG_DIR}/${APP_NAME}_access.log combined
</VirtualHost>
EOF
  ok "VirtualHost: ${SITE_CONF}"

  cat > "${ALIAS_CONF}" <<EOF
# Gerado por deploy.sh (QR Code Fácil). Publica a aplicação em /${APP_NAME}/
# em qualquer host do servidor (útil para acessar pelo IP sem DNS).
Alias /${APP_NAME} "${APP_DIR}"
${dir_block}
EOF
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
  step "Testando a configuração e reiniciando o Apache"
  local test_out
  if ! test_out="$(apache2ctl configtest 2>&1)"; then
    printf '%s\n' "${test_out}" >&2
    die "apache2ctl configtest falhou; o Apache NÃO foi reiniciado"
  fi
  ok "Syntax OK"

  if [[ -d /run/systemd/system ]]; then
    systemctl enable -q apache2
    systemctl restart apache2
  else
    service apache2 restart >/dev/null   # containers sem systemd
  fi
  ok "apache2 reiniciado"

  local check_url="http://127.0.0.1/" host_hdr=()
  # Com o 000-default ativo, a raiz por IP é outro site: valida pelo domínio
  [[ "${DISABLE_DEFAULT_SITE}" == "1" ]] || host_hdr=(-H "Host: ${DOMAIN}")
  if curl -fsS -o /dev/null "${host_hdr[@]}" "${check_url}" && \
     curl -fsS "${host_hdr[@]}" "${check_url}" | grep -q "QR Code Fácil"; then
    ok "aplicação respondendo em ${check_url}"
  else
    warn "não foi possível validar ${check_url} (verifique firewall/logs)"
  fi

  # --------------------------------------------------------------- 8. resumo
  local ip
  ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  ip="${ip:-<ip-do-servidor>}"

  trap - ERR
  cat <<EOF

${C_GREEN}============================================================
  QR Code Fácil instalado com sucesso!  (versão ${version})
============================================================${C_RESET}

  Acesso local / pela rede:
EOF
  if [[ "${DISABLE_DEFAULT_SITE}" == "1" ]]; then
    echo "    http://127.0.0.1/"
    echo "    http://${ip}/"
  fi
  [[ "${ENABLE_ALIAS}" == "1" ]] && echo "    http://${ip}/${APP_NAME}/"
  cat <<EOF
    http://${DOMAIN}/   (após apontar o DNS)

  Arquivos:      ${APP_DIR}
  VirtualHost:   ${SITE_CONF}
  Logs:          /var/log/apache2/${APP_NAME}_*.log

  Apontamento de DNS:
    Crie um registro A (ou AAAA) de "${DOMAIN}" para ${ip}.
    Para testar sem DNS, adicione na máquina cliente (/etc/hosts):
      ${ip}  ${DOMAIN}
EOF
  if [[ "${DOMAIN}" == "qrcode.local" ]]; then
    cat <<EOF

  Domínio próprio: execute novamente informando DOMAIN:
    curl -sSL https://raw.githubusercontent.com/efbecker/qrcode/main/deploy.sh | sudo DOMAIN=qr.exemplo.com bash
EOF
  fi
  cat <<EOF

  HTTPS (Let's Encrypt), depois do DNS propagado:
    apt-get install -y certbot python3-certbot-apache
    certbot --apache -d ${DOMAIN}

  Para atualizar, basta rodar o mesmo comando curl novamente.

EOF
}

main "$@"
