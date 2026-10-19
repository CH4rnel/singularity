/**
 * Homepages for the underground: a template is a whole page.
 *
 * A token's homepage is one HTML file served from its own subdomain under a
 * CSP sandbox (LaunchpadController) — so everything a template needs is
 * inside it: styles, the script that animates it, and nothing fetched but
 * three images (the token's cover, its hit counter) and the links of the ring.
 * The workshop (`WalletWorkshop.vue`) shows the result before it is signed,
 * and the code is the creator's to change; a template is where a page starts,
 * not what it has to stay.
 *
 * Everything the creator typed is escaped on the way in. The page runs in a
 * sandbox with no origin, so a name that broke out could not reach anybody's
 * wallet — but a page that breaks because somebody's token is called
 * `</title>` would still be a broken page.
 */

export const HOMEPAGE_TEMPLATES = [
    'homestead',
    'wired',
    'wires',
    'protocol7',
    'jodi',
    'shrine',
] as const;

export type HomepageTemplate = (typeof HOMEPAGE_TEMPLATES)[number];

/** What a template is filled with. */
export type HomepageSite = {
    name: string;
    symbol: string;
    description: string;
    subdomain: string;
    /** The token's contract; it seeds the cover. */
    address: string;
    /** `https://underground.cyberia.church`, from the directory. */
    undergroundUrl: string;
    buyUrl: string;
    links: { label: string; url: string }[];
};

/** The upload limit the server enforces (`max:2048` kilobytes). */
export const HOMEPAGE_MAX_BYTES = 2048 * 1024;

const ENTITIES: Record<string, string> = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
};

export const escapeHtml = (text: string): string =>
    text.replace(/[&<>"']/g, (char) => ENTITIES[char]);

/** A value for a `<script>`: JSON, with nothing that can close the tag. */
export const scriptValue = (value: unknown): string =>
    JSON.stringify(value)
        .replace(/</g, '\\u003c')
        .replace(/\u2028/g, '\\u2028')
        .replace(/\u2029/g, '\\u2029');

const safeUrl = (url: string): string | null =>
    /^https?:\/\//i.test(url.trim()) ? url.trim() : null;

type Parts = {
    name: string;
    symbol: string;
    description: string;
    host: string;
    cover: string;
    counter: string;
    links: string;
    ring: string;
    buy: string;
    raw: HomepageSite;
};

const parts = (site: HomepageSite): Parts => {
    const base = site.undergroundUrl.replace(/\/$/, '');
    const sub = encodeURIComponent(site.subdomain);
    const symbol = site.symbol.toUpperCase();
    const links = site.links
        .map((link) => ({ label: link.label, url: safeUrl(link.url) }))
        .filter(
            (link): link is { label: string; url: string } => link.url !== null,
        )
        .map(
            (link) =>
                `<a href="${escapeHtml(link.url)}" target="_blank" rel="noopener">${escapeHtml(link.label)}</a>`,
        )
        .join(' ');
    const ring = [
        `<a href="${escapeHtml(`${base}/ring/prev/${sub}`)}">&larr;</a>`,
        `<a href="${escapeHtml(base)}">underground</a>`,
        `<a href="${escapeHtml(`${base}/ring/random/${sub}`)}">?</a>`,
        `<a href="${escapeHtml(`${base}/ring/next/${sub}`)}">&rarr;</a>`,
    ].join(' ');

    return {
        name: escapeHtml(site.name || symbol),
        symbol: escapeHtml(symbol),
        description: escapeHtml(site.description),
        host: escapeHtml(`${site.subdomain}.cyberia.church`),
        cover: escapeHtml(`${base}/cover/${site.address.toLowerCase()}.svg`),
        counter: escapeHtml(`${base}/counter/${sub}.svg`),
        links,
        ring,
        buy: safeUrl(site.buyUrl)
            ? `<a href="${escapeHtml(site.buyUrl)}" target="_blank" rel="noopener">buy $${escapeHtml(symbol)}</a>`
            : '',
        raw: site,
    };
};

const head = (p: Parts, style: string): string => `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="generator" content="Cyberia underground workshop">
<title>${p.name} · $${p.symbol}</title>
<style>
${style}
</style>
</head>`;

/** The strip every template ends with: ring, counter, where it lives. */
const footer = (
    p: Parts,
    className = 'ug',
): string => `<footer class="${className}">
<div class="ug-ring">${p.ring}</div>
<div class="ug-count"><img src="${p.counter}" alt="hit counter" height="30"></div>
<div class="ug-where">${p.host} &middot; a homepage in the <a href="${escapeHtml(p.raw.undergroundUrl)}">Cyberia underground</a></div>
</footer>`;

const reducedMotion =
    "var still=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;";

/* --------------------------------------------------------------- 1997 --- */

const homestead = (p: Parts): string => `${head(
    p,
    `body{margin:0;background:#000;color:#ff0;font:17px/1.5 "Times New Roman",Times,serif;text-align:center;
background-image:radial-gradient(#fff 1px,transparent 1.6px),radial-gradient(#9cf 1px,transparent 1.6px),radial-gradient(#f9c 1px,transparent 1.4px);
background-size:29px 29px,47px 47px,83px 83px;background-position:0 0,13px 21px,40px 7px}
.wrap{max-width:680px;margin:0 auto;padding:12px 14px 40px}
marquee{color:#0f0;font:bold 18px "Courier New",monospace;background:#000080;border:3px ridge #c0c0c0;padding:4px 0}
h1{font:bold clamp(34px,8vw,56px) "Comic Sans MS","Comic Neue",cursive;color:#0ff;text-shadow:3px 3px #f0f,6px 6px #00f;margin:.4em 0 .1em}
.sym{color:#f0f;font:bold 22px "Courier New",monospace}
.uc{height:22px;margin:14px 0;background:repeating-linear-gradient(45deg,#000 0 14px,#fc0 14px 28px);border:2px solid #fc0}
.new{color:#f00;font-weight:bold;animation:b 1s steps(1) infinite}@keyframes b{50%{visibility:hidden}}
.cover{width:100%;max-width:440px;border:6px outset #c0c0c0;display:block;margin:10px auto}
.desc{color:#fff;font-size:19px;margin:16px 0}
hr{border:0;height:5px;background:linear-gradient(90deg,red,orange,yellow,lime,cyan,blue,violet);margin:22px 0}
table{margin:10px auto;border:4px ridge #c0c0c0;background:#000080;color:#fff;border-collapse:separate}
td{padding:8px 14px;border:2px groove #c0c0c0}
a{color:#0f0}a:visited{color:#f0f}
.ug{color:#ccc;font:14px "Courier New",monospace;margin-top:26px}.ug div{margin:8px 0}.ug a{color:#0ff}
.small{font-size:12px;color:#aaa}`,
)}
<body><div class="wrap">
<marquee scrollamount="6">*** WELCOME TO THE HOMEPAGE OF $${p.symbol} *** YOU ARE NOT ALONE *** SIGN MY GUESTBOOK *** CLOSE THE WORLD, OPEN THE NEXT ***</marquee>
<h1>&#10022; ${p.name} &#10022;</h1>
<div class="sym">$${p.symbol}</div>
<div class="uc"></div>
<p>This page is <b>under construction</b>. Come back soon! <span class="new">NEW!</span></p>
<img class="cover" src="${p.cover}" alt="">
<p class="desc">${p.description}</p>
<hr>
<table><tr><td>${p.buy || '&nbsp;'}</td><td>${p.links || 'no links yet'}</td></tr></table>
<p>You are visitor number<br><img src="${p.counter}" alt="hit counter" height="30"></p>
<hr>
${footer(p)}
<p class="small">Best viewed with Netscape Navigator 3.0 at 800&times;600 &middot; made with Notepad &middot; &copy; 1997&ndash;forever</p>
</div></body></html>
`;

/* -------------------------------------------------------------- wired --- */

const wired = (p: Parts): string => `${head(
    p,
    `html,body{margin:0;height:100%;background:#000;color:#b8ffd0;font:15px/1.6 ui-monospace,Menlo,Consolas,monospace}
canvas{position:fixed;inset:0;width:100%;height:100%;opacity:.35}
main{position:relative;max-width:720px;margin:0 auto;padding:28px 18px 60px}
pre{white-space:pre-wrap;color:#5f8f6f;margin:0 0 18px}
h1{font:700 clamp(30px,7vw,58px)/1.05 ui-monospace,Menlo,monospace;color:#e8e4dc;margin:10px 0 4px;text-shadow:3px 0 rgba(215,38,61,.7),-3px 0 rgba(114,220,207,.6)}
.sym{color:#72dccf;letter-spacing:.2em}
.desc{color:#e8e4dc;max-width:60ch;margin:18px 0}
nav a,.ug a{color:#72dccf;margin-right:14px}
#page{opacity:0;transition:opacity .8s}#page.on{opacity:1}
.ug{margin-top:34px;padding-top:14px;border-top:1px dashed #1f3a2a;color:#5f8f6f;font-size:13px}.ug div{margin:6px 0}`,
)}
<body>
<canvas id="rain"></canvas>
<main>
<pre id="boot"></pre>
<section id="page">
<h1>${p.name}</h1>
<div class="sym">$${p.symbol}</div>
<p class="desc">${p.description}</p>
<nav>${p.buy} ${p.links}</nav>
${footer(p)}
</section>
</main>
<script>
(function(){${reducedMotion}
var SYM=${scriptValue(p.raw.symbol.toUpperCase())},HOST=${scriptValue(`${p.raw.subdomain}.cyberia.church`)};
var c=document.getElementById('rain'),x=c.getContext('2d'),cols=0,drops=[];
var glyphs='\\uff71\\uff72\\uff73\\uff74\\uff75\\uff76\\uff77\\uff78\\uff79\\uff7a0123456789ABCDEF'+SYM;
function size(){c.width=innerWidth;c.height=innerHeight;cols=Math.ceil(c.width/16);drops=[];for(var i=0;i<cols;i++){drops[i]=Math.random()*-c.height;}}
size();addEventListener('resize',size);
function rain(){x.fillStyle='rgba(0,0,0,.12)';x.fillRect(0,0,c.width,c.height);x.fillStyle='#72dccf';x.font='14px monospace';
for(var i=0;i<cols;i++){x.fillText(glyphs.charAt(Math.floor(Math.random()*glyphs.length)),i*16,drops[i]);drops[i]=drops[i]>c.height&&Math.random()>.975?0:drops[i]+16;}}
if(still){for(var k=0;k<60;k++){rain();}}else{setInterval(rain,55);}
var lines=['CYBERIA BIOS v13.07','CONNECTING TO THE WIRED .......... OK','NODE '+HOST,'PROTOCOL 7 HANDSHAKE ............ OK','MOUNTING /home/'+SYM.toLowerCase()+' ............ OK','PRESENT DAY. PRESENT TIME.',''];
var boot=document.getElementById('boot'),n=0;
(function next(){if(n<lines.length){boot.textContent+=lines[n++]+'\\n';setTimeout(next,still?0:240);}else{document.getElementById('page').className='on';}})();
})();
</script>
</body></html>
`;

/* -------------------------------------------------------------- wires --- */

const wires = (p: Parts): string => `${head(
    p,
    `html,body{margin:0;min-height:100%;background:#e9e4d8;color:#111;font:16px/1.55 Georgia,"Times New Roman",serif}
canvas{position:fixed;inset:0;width:100%;height:100%;z-index:0}
main{position:relative;z-index:1;max-width:640px;margin:0 auto;padding:22vh 18px 60px}
h1{font:400 clamp(40px,9vw,84px)/1 Georgia,serif;margin:0;letter-spacing:-.02em}
.sym{font:600 14px ui-monospace,Menlo,monospace;letter-spacing:.3em;color:#a3142a;margin:8px 0 22px}
.paper{background:rgba(243,239,230,.88);padding:18px 20px;border-left:3px solid #a3142a}
nav{margin-top:14px}nav a,.ug a{color:#a3142a;margin-right:14px}
.ug{margin-top:26px;font:13px ui-monospace,Menlo,monospace;color:#444}.ug div{margin:6px 0}`,
)}
<body>
<canvas id="sky"></canvas>
<main>
<h1>${p.name}</h1>
<div class="sym">$${p.symbol}</div>
<div class="paper">
<p>${p.description}</p>
<nav>${p.buy} ${p.links}</nav>
</div>
${footer(p)}
</main>
<script>
(function(){${reducedMotion}
var c=document.getElementById('sky'),x=c.getContext('2d'),poles=[],birds=[];
function setup(){c.width=innerWidth;c.height=innerHeight;poles=[];var px=-40;while(px<c.width+160){var top=c.height*(0.12+Math.random()*0.25);poles.push({x:px,top:top});px+=180+Math.random()*160;}
birds=[];for(var i=0;i<7;i++){birds.push({span:Math.floor(Math.random()*Math.max(1,poles.length-1)),t:0.2+Math.random()*0.6,w:Math.floor(Math.random()*3)});}}
setup();addEventListener('resize',setup);
function draw(time){var w=c.width,h=c.height,g=x.createLinearGradient(0,0,0,h);g.addColorStop(0,'#f1ede4');g.addColorStop(.7,'#ddd5c6');g.addColorStop(1,'#c96a5a');x.fillStyle=g;x.fillRect(0,0,w,h);
x.fillStyle='rgba(215,38,61,.85)';x.beginPath();x.arc(w*.72,h*.22,Math.min(w,h)*.06,0,7);x.fill();x.strokeStyle='#111';
poles.forEach(function(p){x.lineWidth=9;x.beginPath();x.moveTo(p.x,h);x.lineTo(p.x,p.top);x.stroke();x.lineWidth=4;x.beginPath();x.moveTo(p.x-40,p.top+16);x.lineTo(p.x+40,p.top+16);x.moveTo(p.x-40,p.top+38);x.lineTo(p.x+40,p.top+38);x.stroke();});
x.lineWidth=1.3;var sway=Math.sin(time/1400)*6;
for(var i=0;i<poles.length-1;i++){var a=poles[i],b=poles[i+1];[-32,0,32].forEach(function(o,wi){var y1=a.top+(wi===1?38:16),y2=b.top+(wi===1?38:16),sag=40+wi*8+sway;x.beginPath();x.moveTo(a.x+o,y1);x.quadraticCurveTo((a.x+b.x)/2+o,(y1+y2)/2+sag*2,b.x+o,y2);x.stroke();
birds.forEach(function(bd){if(bd.span===i&&bd.w===wi){var t=bd.t,bx=(1-t)*(1-t)*(a.x+o)+2*(1-t)*t*((a.x+b.x)/2+o)+t*t*(b.x+o),by=(1-t)*(1-t)*y1+2*(1-t)*t*((y1+y2)/2+sag*2)+t*t*y2;x.fillStyle='#111';x.beginPath();x.ellipse(bx,by-7,6,8,0,0,7);x.fill();x.beginPath();x.arc(bx+4,by-15,3.5,0,7);x.fill();}});});}
if(!still){requestAnimationFrame(draw);}}
requestAnimationFrame(draw);
})();
</script>
</body></html>
`;

/* ---------------------------------------------------------- protocol 7 --- */

const protocol7 = (p: Parts): string => `${head(
    p,
    `html,body{margin:0;min-height:100%;background:#07080b;color:#e8e4dc;font:15px/1.6 ui-monospace,Menlo,Consolas,monospace}
canvas{position:fixed;inset:0;width:100%;height:100%}
main{position:relative;max-width:560px;margin:0 auto;padding:62vh 18px 60px}
.panel{background:rgba(7,8,11,.82);border:1px solid #1f242b;padding:18px 20px}
h1{font:600 clamp(24px,5vw,38px)/1.15 ui-monospace,Menlo,monospace;margin:0 0 4px;letter-spacing:.04em}
.sym{color:#72dccf;letter-spacing:.24em}
nav a,.ug a{color:#72dccf;margin-right:14px}
.ug{margin-top:20px;color:#8d959e;font-size:13px}.ug div{margin:6px 0}`,
)}
<body>
<canvas id="hud"></canvas>
<main><div class="panel">
<h1>${p.name}</h1>
<div class="sym">$${p.symbol} &middot; protocol 7</div>
<p>${p.description}</p>
<nav>${p.buy} ${p.links}</nav>
${footer(p)}
</div></main>
<script>
(function(){${reducedMotion}
var SYM=${scriptValue(`$${p.raw.symbol.toUpperCase()}`)};
var c=document.getElementById('hud'),x=c.getContext('2d'),rings=[];
for(var i=0;i<32;i++){rings.push({r:.05+Math.random()*.42,a:Math.random()*6.28,s:.2+Math.random()*4.4,v:(Math.random()-.5)*.012,w:1+Math.random()*7,c:Math.random()<.08?'#d7263d':Math.random()<.4?'#72dccf':'#e8e4dc',o:.35+Math.random()*.65});}
function draw(){c.width=innerWidth;c.height=innerHeight;var m=Math.min(c.width,c.height),cx=c.width/2,cy=Math.min(c.height*.34,m*.5);x.fillStyle='#07080b';x.fillRect(0,0,c.width,c.height);
rings.forEach(function(r){r.a+=r.v;x.globalAlpha=r.o;x.strokeStyle=r.c;x.lineWidth=r.w;x.beginPath();x.arc(cx,cy,r.r*m*.8,r.a,r.a+r.s);x.stroke();});
x.globalAlpha=1;x.fillStyle='#e8e4dc';x.font='600 '+Math.round(m*.06)+'px monospace';x.textAlign='center';x.textBaseline='middle';x.fillText(SYM,cx,cy);
if(!still){requestAnimationFrame(draw);}}
draw();
})();
</script>
</body></html>
`;

/* --------------------------------------------------------------- jodi --- */

const jodi = (p: Parts): string => `${head(
    p,
    `html,body{margin:0;min-height:100%;background:#000;color:#0f0;font:14px/1.2 "Courier New",monospace;overflow-x:hidden}
#noise{position:fixed;inset:0;overflow:hidden;white-space:pre;color:#0f0;opacity:.85}
#noise span{position:absolute}
.escape{position:relative;z-index:2;max-width:520px;margin:18vh auto 40px;padding:16px;background:#000;border:2px solid #0f0;color:#0f0}
.escape h1{font:bold 30px "Courier New",monospace;margin:0;color:#fff;background:#00f;display:inline-block;padding:0 6px}
.escape a{color:#ff0;margin-right:12px}
.ug{margin-top:14px;font-size:12px}.ug div{margin:6px 0}`,
)}
<body>
<div id="noise" aria-hidden="true"></div>
<div class="escape">
<h1>${p.name}</h1>
<p>$${p.symbol} / ${p.host}</p>
<p>${p.description}</p>
<p>${p.buy} ${p.links}</p>
${footer(p)}
</div>
<script>
(function(){${reducedMotion}
var WORD=${scriptValue(p.raw.symbol.toUpperCase())},n=document.getElementById('noise');
var bits=['%','#','@','/','\\\\','|','0','1','::',':;',WORD,WORD.toLowerCase(),'[ ]','<<','>>','.:.','ERROR','404','www','http://','***'];
var colors=['#0f0','#f0f','#ff0','#0ff','#fff','#f00','#00f'];
function scatter(){var s=document.createElement('span');s.textContent=bits[Math.floor(Math.random()*bits.length)].repeat(1+Math.floor(Math.random()*6));
s.style.left=Math.floor(Math.random()*100)+'%';s.style.top=Math.floor(Math.random()*100)+'%';s.style.color=colors[Math.floor(Math.random()*colors.length)];s.style.fontSize=(8+Math.floor(Math.random()*40))+'px';
if(Math.random()<.2){s.style.background=colors[Math.floor(Math.random()*colors.length)];s.style.color='#000';}
n.appendChild(s);if(n.childNodes.length>160){n.removeChild(n.firstChild);}}
for(var i=0;i<120;i++){scatter();}
if(!still){setInterval(scatter,90);setInterval(function(){document.body.style.background=Math.random()<.04?'#00f':'#000';},700);}
})();
</script>
</body></html>
`;

/* ------------------------------------------------------------- shrine --- */

const shrine = (p: Parts): string => `${head(
    p,
    `html,body{margin:0;background:#f3efe6;color:#1a1714;font:18px/1.65 Georgia,"Times New Roman",serif}
main{max-width:600px;margin:0 auto;padding:44px 20px 60px;text-align:center}
.frame{display:inline-block;padding:10px;border:3px double #8a6d3b;background:#fff;box-shadow:0 0 0 8px #f3efe6,0 0 0 9px #8a6d3b}
.frame img{display:block;width:min(100%,420px)}
h1{font:400 clamp(34px,7vw,58px)/1.1 Georgia,serif;margin:28px 0 6px}
.sym{font:600 13px ui-monospace,Menlo,monospace;letter-spacing:.32em;color:#8a6d3b}
.osc{display:inline-grid;font-style:italic;color:#a3142a;margin:18px 0}
.osc span{grid-area:1/1;opacity:0;animation:osc 9s infinite}.osc span:nth-child(2){animation-delay:3s}.osc span:nth-child(3){animation-delay:6s}
@keyframes osc{0%,30%{opacity:1}33.4%,100%{opacity:0}}
.candle{width:14px;height:46px;margin:22px auto 0;background:linear-gradient(#fffaf0,#e8dcc4);position:relative;border-radius:2px}
.candle:before{content:"";position:absolute;left:50%;top:-26px;width:12px;height:24px;margin-left:-6px;border-radius:50% 50% 45% 45%;background:radial-gradient(#fff6c2,#f2a33a 60%,rgba(242,163,58,0));animation:flame 1.6s ease-in-out infinite alternate}
@keyframes flame{to{transform:scale(.9,1.12) rotate(2deg);opacity:.85}}
.desc{margin:18px 0}
nav a,.ug a{color:#a3142a;margin:0 8px}
.ug{margin-top:30px;font:13px ui-monospace,Menlo,monospace;color:#6b6257}.ug div{margin:6px 0}
@media (prefers-reduced-motion: reduce){.osc span,.candle:before{animation:none}.osc span:first-child{opacity:1}}`,
)}
<body><main>
<div class="frame"><img src="${p.cover}" alt=""></div>
<h1>${p.name}</h1>
<div class="sym">$${p.symbol}</div>
<div class="osc"><span>we believe in this</span><span>we are pretending to believe in this</span><span>we believe in this</span></div>
<p class="desc">${p.description}</p>
<nav>${p.buy} ${p.links}</nav>
<div class="candle" aria-hidden="true"></div>
${footer(p)}
</main></body></html>
`;

const TEMPLATES: Record<HomepageTemplate, (p: Parts) => string> = {
    homestead,
    wired,
    wires,
    protocol7,
    jodi,
    shrine,
};

/** One template, filled. The result is the whole file that gets uploaded. */
export const buildHomepage = (
    template: HomepageTemplate,
    site: HomepageSite,
): string => TEMPLATES[template](parts(site));

/* ----------------------------------------------------------- subdomain --- */

/** The DNS label the server accepts (`LaunchpadController::store`). */
export const SUBDOMAIN_PATTERN = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

/**
 * A first suggestion for a token's subdomain: its ticker, lowercased, with
 * anything a DNS label cannot hold taken out. The server has the last word on
 * whether it is free.
 */
export const suggestSubdomain = (symbol: string): string => {
    const label = symbol
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '')
        .replace(/^-+|-+$/g, '')
        .slice(0, 63);

    return SUBDOMAIN_PATTERN.test(label) ? label : '';
};
