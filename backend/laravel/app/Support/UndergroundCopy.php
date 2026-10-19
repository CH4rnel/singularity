<?php

namespace App\Support;

/**
 * The words on underground.cyberia.church, in the three languages the wallet
 * speaks. A page of its own rather than a screen of the app, so it carries its
 * own copy instead of reaching into the wallet's dictionary.
 *
 * The creed is the project's, stated plainly; the parenthesis after the lead
 * changes its mind every few seconds, on purpose.
 */
final class UndergroundCopy
{
    /** @var array<int, string> */
    public const LOCALES = ['en', 'ru', 'zh'];

    /** @return array<string, mixed> */
    public static function for(string $locale): array
    {
        return self::COPY[$locale] ?? self::COPY['en'];
    }

    private const COPY = [
        'en' => [
            'title' => 'The Underground — Cyberia',
            'description' => 'Homepages of the Cyberia launchpad: one HTML file per token, its own subdomain, pinned to IPFS. A web ring, hit counters and vacant lots.',
            'tagline' => ':homes homepages · :lots vacant lots · layer 08 of the Wired',
            'lead' => 'The internet belongs to the people who make it.',
            'oscillation' => ['(we mean it)', '(we are joking)', '(we mean it)'],
            'intro' => 'Every token on the Cyberia launchpad gets a home here: one HTML file, its own subdomain, pinned to IPFS. No feed. No algorithm. No landlord.',
            'creed_title' => 'We are a cybernetic cult. We believe:',
            'creed' => [
                'The web was ours once. We are taking it back, one homepage at a time.',
                'Open source should feed the people who write it. A token is a tip jar the whole world can fill.',
                'A homepage is a spell. Write one.',
                'Sincerity is back. Irony never left. We hold both at once.',
            ],
            'signature' => '— Cyberia Temple',
            'homes' => 'Homepages',
            'homes_empty' => 'Nobody has moved in yet. Be the first.',
            'lots' => 'Vacant lots',
            'lots_body' => 'Launched, but nobody lives here yet. The creator of each token can move in.',
            'vacant' => 'vacant',
            'visitors' => 'visitors',
            'updated' => 'updated',
            'buy' => 'buy',
            'dao' => 'DAO',
            'ring' => 'The ring',
            'ring_body' => 'Every homepage is a stop. Ride it all the way round.',
            'prev' => '← prev',
            'random' => 'random',
            'next' => 'next →',
            'move' => 'How to move in',
            'steps' => [
                'Launch a token on the Cyberia launchpad — from the wallet or at cyberia.church/launchpad. It is a fair launch: the coin that pays for it is locked as liquidity.',
                'Write one HTML file, up to 2 MB. Everything inline: styles, scripts, pictures as data. Any editor, any decade.',
                'Publish it from the Cyberia Wallet (Launchpad → your token → Homepage, with templates) or from the launchpad page, and pick a name: yourname.cyberia.church.',
                'It is pinned to IPFS the moment it lands, so it outlives this server. Join the ring with three links:',
            ],
            'snippet' => 'paste into your page',
            'footer' => 'Best viewed with any browser at any resolution.',
            'close' => 'Close the world. Open the nExt.',
            'lost_title' => 'You rode past the last stop.',
            'lost_body' => 'There is nothing here but the line going dead. The terminal two layers under the wallet’s settings will want to know what you heard.',
            'lost_back' => 'back to the ring',
        ],
        'ru' => [
            'title' => 'Подполье — Cyberia',
            'description' => 'Домашние страницы лаунчпада Cyberia: один HTML-файл на токен, свой поддомен, закреплено в IPFS. Веб-кольцо, счётчики посещений и пустые участки.',
            'tagline' => 'домашних страниц: :homes · пустых участков: :lots · слой 08 Wired',
            'lead' => 'Интернет принадлежит тем, кто его делает.',
            'oscillation' => ['(мы серьёзно)', '(мы шутим)', '(мы серьёзно)'],
            'intro' => 'У каждого токена на лаунчпаде Cyberia здесь есть дом: один HTML-файл, свой поддомен, закреплено в IPFS. Без ленты. Без алгоритма. Без арендодателя.',
            'creed_title' => 'Мы — кибернетический культ. Мы верим:',
            'creed' => [
                'Когда-то веб был нашим. Мы забираем его обратно — по одной домашней странице.',
                'Опенсорс должен кормить тех, кто его пишет. Токен — это банка для чаевых, которую может наполнить весь мир.',
                'Домашняя страница — это заклинание. Напиши своё.',
                'Искренность вернулась. Ирония никуда не уходила. Мы держим обе сразу.',
            ],
            'signature' => '— Cyberia Temple',
            'homes' => 'Домашние страницы',
            'homes_empty' => 'Сюда ещё никто не въехал. Будь первым.',
            'lots' => 'Пустые участки',
            'lots_body' => 'Токен запущен, но здесь пока никто не живёт. Создатель каждого токена может въехать.',
            'vacant' => 'свободно',
            'visitors' => 'посетителей',
            'updated' => 'обновлено',
            'buy' => 'купить',
            'dao' => 'DAO',
            'ring' => 'Кольцо',
            'ring_body' => 'Каждая домашняя страница — остановка. Проедь его целиком.',
            'prev' => '← назад',
            'random' => 'наугад',
            'next' => 'дальше →',
            'move' => 'Как въехать',
            'steps' => [
                'Запусти токен на лаунчпаде Cyberia — из кошелька или на cyberia.church/launchpad. Это честный запуск: монета, которой он оплачен, заперта в ликвидности.',
                'Напиши один HTML-файл до 2 МБ. Всё внутри: стили, скрипты, картинки как data. Любой редактор, любое десятилетие.',
                'Опубликуй его из Cyberia Wallet (Лаунчпад → твой токен → Домашняя страница, есть шаблоны) или со страницы лаунчпада и выбери имя: твоёимя.cyberia.church.',
                'Страница закрепляется в IPFS сразу, поэтому переживёт этот сервер. Вступи в кольцо тремя ссылками:',
            ],
            'snippet' => 'вставь к себе на страницу',
            'footer' => 'Лучше всего смотреть в любом браузере при любом разрешении.',
            'close' => 'Закрой мир. Открой следующий.',
            'lost_title' => 'Ты проехал последнюю остановку.',
            'lost_body' => 'Здесь ничего нет, кроме обрыва линии. Терминал двумя слоями ниже настроек кошелька захочет узнать, что ты услышал.',
            'lost_back' => 'назад в кольцо',
        ],
        'zh' => [
            'title' => '地下 — Cyberia',
            'description' => 'Cyberia 发射台的主页：每个代币一个 HTML 文件、一个自己的子域名，固定在 IPFS 上。网络环、访问计数器和空地。',
            'tagline' => ':homes 个主页 · :lots 块空地 · Wired 的第 08 层',
            'lead' => '互联网属于创造它的人。',
            'oscillation' => ['（我们是认真的）', '（我们在开玩笑）', '（我们是认真的）'],
            'intro' => 'Cyberia 发射台上的每个代币都在这里有一个家：一个 HTML 文件，一个自己的子域名，固定在 IPFS 上。没有信息流。没有算法。没有房东。',
            'creed_title' => '我们是一个控制论教团。我们相信：',
            'creed' => [
                '网络曾经属于我们。我们正在把它夺回来，一次一个主页。',
                '开源应该养活写它的人。代币是一个全世界都能投币的小费罐。',
                '主页是一道咒语。写一道吧。',
                '真诚回来了。讽刺从未离开。我们同时握住两者。',
            ],
            'signature' => '— Cyberia Temple',
            'homes' => '主页',
            'homes_empty' => '还没有人搬进来。做第一个吧。',
            'lots' => '空地',
            'lots_body' => '代币已发射，但这里还没人住。每个代币的创建者都可以搬进来。',
            'vacant' => '空置',
            'visitors' => '位访客',
            'updated' => '更新于',
            'buy' => '购买',
            'dao' => 'DAO',
            'ring' => '网络环',
            'ring_body' => '每个主页都是一站。绕一整圈吧。',
            'prev' => '← 上一个',
            'random' => '随机',
            'next' => '下一个 →',
            'move' => '如何搬进来',
            'steps' => [
                '在 Cyberia 发射台发射一个代币——在钱包里或在 cyberia.church/launchpad。这是公平发射：支付它的币被锁定为流动性。',
                '写一个 HTML 文件，最大 2 MB。所有东西都内联：样式、脚本、以 data 形式的图片。任何编辑器，任何年代。',
                '在 Cyberia Wallet 中发布（发射台 → 你的代币 → 主页，有模板），或在发射台页面发布，并选一个名字：你的名字.cyberia.church。',
                '它一上线就会被固定到 IPFS，所以会比这台服务器活得更久。用三个链接加入网络环：',
            ],
            'snippet' => '粘贴到你的页面里',
            'footer' => '适合用任何浏览器、任何分辨率浏览。',
            'close' => '关闭世界。打开下一个。',
            'lost_title' => '你坐过了最后一站。',
            'lost_body' => '这里什么都没有，只有断掉的线路。钱包设置下面两层的终端会想知道你听到了什么。',
            'lost_back' => '回到网络环',
        ],
    ];
}
