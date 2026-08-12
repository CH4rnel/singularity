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
        extension: 'Browser extension',
        'extension-zip': 'Chrome · Brave · Edge',
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
        extensionNote:
            'Unzip it, open chrome://extensions, turn on Developer mode and press Load unpacked. It is not in any web store yet. Unlike the apps, the extension is a wallet of its own: it signs for dapps in the page, and you can import the same seed phrase to see the same accounts.',
        openInBrowser: 'Open the wallet in the browser',
        webAlternative:
            'You do not need any of this to use the wallet — it runs in a browser tab, with the same keys.',
        keysNote:
            'The apps are the same shell around cyberia.church; the browser extension is the one build that holds its own vault. Either way keys are generated on your device and encrypted with your password, and installing nothing sends them anywhere.',
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
        extension: 'Расширение для браузера',
        'extension-zip': 'Chrome · Brave · Edge',
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
        extensionNote:
            'Распакуйте архив, откройте chrome://extensions, включите режим разработчика и нажмите «Загрузить распакованное расширение». В магазинах его пока нет. В отличие от приложений, расширение — самостоятельный кошелёк: оно подписывает транзакции прямо на странице сайта, а если ввести ту же seed-фразу, счета будут те же.',
        openInBrowser: 'Открыть кошелёк в браузере',
        webAlternative:
            'Ничего из этого не обязательно: кошелёк работает во вкладке браузера с теми же ключами.',
        keysNote:
            'Приложения — одна и та же оболочка вокруг cyberia.church; расширение для браузера — единственная сборка со своим хранилищем. В обоих случаях ключи создаются на устройстве и шифруются вашим паролем, и установка никуда их не отправляет.',
        checksums: 'Контрольные суммы SHA-256',
        allReleases: 'Все релизы',
        sourceCode: 'Исходный код',
    },
};
