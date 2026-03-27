Run in chroot:
```
apt update

apt install -y \
git \
curl \
wget \
htop \
neovim \
tmux \
build-essential \
docker.io \
docker-compose \
python3-pip
```
/etc/skel/.bashrc:
```bash
HISTSIZE=2000 # increase history size
HISTFILESIZE=4000
shopt -s globstar # recursive ls **
PS1='${debian_chroot:+($debian_chroot)}\[\033[01;36m\]\u@\h\[\033[00m\]:\[\033[01;35m\]\w\[\033[00m\]\$ ' # 35m & 36m - magenta & cyan colors

```
/etc/os-release:
```
NAME="Cyberia Linux"
VERSION="0.0.1 (Zero)"
ID=cyberialinux
ID_LIKE="ubuntu debian"
PRETTY_NAME="Cyberia OS 0.0.1 (Cubic 2026-03-13 12:51)"
VERSION_ID="0.0.1"
HOME_URL="https://cyberia-temple.github.io/"
SUPPORT_URL="https://github.com/cyberia-temple/singularity/issues"
BUG_REPORT_URL="https://github.com/cyberia-temple/singularity/issues"
PRIVACY_POLICY_URL="https://cyberia-temple.github.io/"
VERSION_CODENAME=zero
UBUNTU_CODENAME=noble
```
/etc/issue:
```
Cyberia Linux 0.0.1 Zero \n \l
```
/etc/lsb-release:
```
DISTRIB_ID=CyberiaLinux
DISTRIB_RELEASE=0.0.1
DISTRIB_CODENAME=zero
DISTRIB_DESCRIPTION="Cyberia OS 0.0.1"
```
/home/lain/singularity/linux/custom-disk/boot/grub:
```
menuentry "Start Cyberia OS" --class linuxmint {
menuentry "Start Cyberia OS (compatibility mode)" {
```

/etc/linuxmint/info:
```
RELEASE=0.0.1
CODENAME=zero
EDITION="Cinnamon"
DESCRIPTION="Cyberia Linux 0.0.1 Zero"
DESKTOP=Gnome
TOOLKIT=GTK
NEW_FEATURES_URL=https://cyberia-temple.github.io/
RELEASE_NOTES_URL=https://cyberia-temple.github.io/
USER_GUIDE_URL=https://cyberia-temple.github.io/
GRUB_TITLE=Cyberia Linux 0.0.1 Cinnamon
```