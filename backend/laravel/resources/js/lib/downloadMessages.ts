import type { Messages } from '@/composables/useLocale';

/**
 * Strings for /download.
 *
 * Bilingual for the same reason the wallet is: most of the people who are sent
 * an APK link read Russian first, and an install warning they cannot read is an
 * install they abandon.
 */
export const downloadMessages: Messages = {
    en: {
        eyebrow: 'Apps',
        title: 'Download',
        intro: 'The Cyberia wallet as a native app. It wraps the live site, so it updates when the site does — you only ever reinstall when the app itself changes.',
        version: 'Version {version}',
        published: 'published {date}',
        unpublished: 'No build has been published yet.',
        unpublishedHint:
            'The installers are built and released from the repository. Until the first release is tagged, run the app from source.',
        unverified:
            'GitHub could not be reached, so the version and size are unknown. The links below always point at the newest release.',
        forYou: 'For this device',
        otherPlatforms: 'Other platforms',
        download: 'Download',
        notPublished: 'Not published yet',
        buildFromSource: 'Build from source',
        windows: 'Windows',
        macos: 'macOS',
        linux: 'Linux',
        android: 'Android',
        ios: 'iPhone and iPad',
        'windows-installer': 'Installer',
        'windows-portable': 'Portable, no install',
        'macos-arm64': 'Apple Silicon',
        'macos-x64': 'Intel',
        'linux-appimage': 'AppImage',
        'linux-deb': 'Debian / Ubuntu',
        'android-apk': 'APK',
        windowsNote:
            'The installer is not signed, so Windows shows “Windows protected your PC”. Choose More info, then Run anyway.',
        macosNote:
            'The app is not signed, so the first launch has to be right-click → Open → Open. After that it opens normally.',
        linuxNote:
            'Make the AppImage executable (chmod +x) and run it, or install the .deb with your package manager.',
        androidNote:
            'Android asks for permission to install apps from your browser the first time. There is no Play listing — the APK is downloaded from here.',
        iosNote:
            'There is no App Store build. Open cyberia.church in Safari, tap Share, then Add to Home Screen — the wallet runs full screen and works offline the same way.',
        openInBrowser: 'Open the wallet in the browser',
        webAlternative:
            'You do not need any of this to use the wallet — it runs in a browser tab, with the same keys.',
        keysNote:
            'Every build is the same shell around cyberia.church. Keys are generated on your device and encrypted with your password; installing the app does not send them anywhere.',
        checksums: 'SHA-256 checksums',
        allReleases: 'All releases',
        sourceCode: 'Source code',
    },
    ru: {
        eyebrow: 'Приложения',
        title: 'Скачать',
        intro: 'Кошелёк Cyberia как приложение. Внутри — живой сайт, поэтому приложение обновляется вместе с ним: переустановка нужна, только когда меняется сама оболочка.',
        version: 'Версия {version}',
        published: 'опубликовано {date}',
        unpublished: 'Сборка ещё не опубликована.',
        unpublishedHint:
            'Установщики собираются и публикуются из репозитория. Пока первый релиз не выпущен, приложение можно запустить из исходников.',
        unverified:
            'GitHub недоступен, поэтому версия и размер неизвестны. Ссылки ниже всегда ведут на самый свежий релиз.',
        forYou: 'Для этого устройства',
        otherPlatforms: 'Другие платформы',
        download: 'Скачать',
        notPublished: 'Пока не опубликовано',
        buildFromSource: 'Собрать из исходников',
        windows: 'Windows',
        macos: 'macOS',
        linux: 'Linux',
        android: 'Android',
        ios: 'iPhone и iPad',
        'windows-installer': 'Установщик',
        'windows-portable': 'Портативная, без установки',
        'macos-arm64': 'Apple Silicon',
        'macos-x64': 'Intel',
        'linux-appimage': 'AppImage',
        'linux-deb': 'Debian / Ubuntu',
        'android-apk': 'APK',
        windowsNote:
            'Установщик без подписи, поэтому Windows покажет «Система Windows защитила ваш компьютер». Нажмите «Подробнее» → «Выполнить в любом случае».',
        macosNote:
            'Приложение без подписи, поэтому первый запуск — правой кнопкой → «Открыть» → «Открыть». Дальше открывается обычно.',
        linuxNote:
            'Сделайте AppImage исполняемым (chmod +x) и запустите, либо установите .deb пакетным менеджером.',
        androidNote:
            'При первой установке Android попросит разрешить установку приложений из браузера. Приложения нет в Play — APK скачивается отсюда.',
        iosNote:
            'Сборки для App Store нет. Откройте cyberia.church в Safari, нажмите «Поделиться» → «На экран «Домой»» — кошелёк откроется на весь экран.',
        openInBrowser: 'Открыть кошелёк в браузере',
        webAlternative:
            'Ничего из этого не обязательно: кошелёк работает во вкладке браузера с теми же ключами.',
        keysNote:
            'Любая сборка — одна и та же оболочка вокруг cyberia.church. Ключи создаются на устройстве и шифруются вашим паролем; установка приложения никуда их не отправляет.',
        checksums: 'Контрольные суммы SHA-256',
        allReleases: 'Все релизы',
        sourceCode: 'Исходный код',
    },
};
