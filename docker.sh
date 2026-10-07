# Add Docker's official GPG key:
apt update
apt install ca-certificates curl -y
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

# Add the repository to Apt sources:
tee /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

apt update
apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin



cloudflared tunnel create ceti
cloudflared tunnel route dns ceti webweb.daimo.lol
cat << 'EOF' > /usr/local/etc/cloudflared/cloudflared.config-ceti.yml
tunnel: 9f1e9a95-e358-4186-b963-329e934e5687
credentials-file: /root/.cloudflared/9f1e9a95-e358-4186-b963-329e934e5687.json

ingress:
  - hostname: webweb.daimo.lol
    service: http://10.10.10.13:8080
  - service: http_status:404
EOF
ln -sf /etc/init.d/cloudflared /etc/init.d/cloudflared.config-ceti
rc-service cloudflared.config-ceti start
rc-update add cloudflared.config-ceti default

rc-service cloudflared.config-ceti status

rc-service cloudflared.config-ceti restart
