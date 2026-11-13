import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const artifacts = path.join(root, 'storage/app/private');
mkdirSync(artifacts, { recursive: true });
const chrome = [process.env.CHROME_BIN, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/chromium'].find(candidate => candidate && existsSync(candidate));
assert.ok(chrome, 'Chrome or Edge is required.');
const profile = mkdtempSync(path.join(artifacts, 'chat-design-chrome-'));
const fixture = path.join(artifacts, 'chat-design-preview.html');
let view = readFileSync(path.join(root, 'resources/views/chat/index.blade.php'), 'utf8');
view = view.slice(view.indexOf('<div id="procura-chat"'), view.lastIndexOf('@endsection'))
    .replace(/^\s*@unless[^\n]*\n[\s\S]*?^\s*@endunless/gm, '')
    .replace(/^\s*@else\s*\n[\s\S]*?^\s*@endif/gm, '')
    .replace(/^\s*@(?:if|endif)[^\n]*$/gm, '')
    .replace(/{{[\s\S]*?}}/g, 'AXA')
    .replace('data-campaign-home="AXA"', 'data-campaign-home="true"')
    .replace('data-base="AXA"', 'data-base="/chat-data"')
    .replace('data-user="AXA"', 'data-user="1"')
    .replace('data-request="AXA"', 'data-request=""');
const request = { id: 42, request_number: 'REQ-000135', title: 'Office equipment', status: 'FOR_IT_SCREENING', campaign: 'AXA', requestor: 'Jovie', team: 'IT', responsible_role: 'IT Screener', purpose: 'For office supplies and equipment.', total_amount: 125000, items: [], quotations: [], attachments: [] };
const response = { request, permissions: { send: true }, workflow: [
    {name:'Campaign Requestor',state:'visited'}, {name:'OM',state:'visited'}, {name:'IT Screening',state:'current'}, {name:'Premises',state:'not_visited'}, {name:'Purchasing / PO Checking',state:'not_visited'}, {name:'President',state:'not_visited'}, {name:'Budget / Finance',state:'not_visited'}, {name:'Completed',state:'not_visited'},
], messages: [
    {id:1,user_id:2,user_name:'Jovie',kind:'message',body:'Please check if this item is available in inventory.',created_at:'2026-11-07T14:30:00',attachment:{name:'quotation.pdf',url:'#'}},
    {id:2,user_id:3,user_name:'OM Sonny',kind:'message',body:"Noted. We will coordinate with IT for screening.",created_at:'2026-11-07T14:45:00'},
    {id:3,user_id:1,user_name:'Mark Kevin Romero',kind:'message',body:'Great! Proceed to the next stage.',created_at:'2026-11-07T15:20:00'},
], has_more:false };
const source = readFileSync(path.join(root,'resources/js/procura-chat.js'),'utf8').replace(/^import .*;\s*/,'');
const css = readFileSync(path.join(root,'resources/css/procura-chat.css'),'utf8');
const mobile = readFileSync(path.join(root,'resources/css/mobile.css'),'utf8');
const theme = readFileSync(path.join(root,'resources/css/workspace-theme.css'),'utf8').replace(/^@import .*;$/gm, '');
const logo = pathToFileURL(path.join(root,'public/assets/images/asi-procura-logo-transparent.png')).href;
let frameStart = '<aside class="offcanvas-lg offcanvas-start workspace-sidebar" id="workspaceSidebar"><div class="workspace-sidebar-brand">ASI PROCURA</div><div class="workspace-sidebar-role">Workspace<strong>OM</strong></div><nav class="workspace-sidebar-links"><a class="workspace-sidebar-link">Dashboard</a><a class="workspace-sidebar-link">Procura Chat</a><a class="workspace-sidebar-link">Requests</a></nav><div class="workspace-sidebar-footer">Sign out</div></aside><nav class="app-navbar app-navbar--workspace navbar sticky-top"><div class="container-fluid">Welcome back, OM</div></nav><header class="mobile-app-header"><a class="mobile-app-brand"><img class="mobile-brand-logo" src="'+logo+'" alt="ASI Procura"></a><div class="mobile-app-tools"><button type="button" class="mobile-chat-search" data-chat-search-toggle aria-label="Search requests" aria-controls="pc-search-panel" aria-expanded="false"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg></button><a>Alerts</a><a>OM</a></div><div class="mobile-app-heading"><strong>Procura Chat</strong><small>IT DEPARTMENT</small></div></header><main class="app-main" id="workspace-content">';
frameStart = frameStart
    .replace('id="workspaceSidebar">', 'id="workspaceSidebar" tabindex="-1" aria-label="Workspace menu">')
    .replace('<div class="workspace-sidebar-role">', '<div class="workspace-drawer-profile"><span class="workspace-drawer-avatar">OM</span><div class="workspace-drawer-person"><strong>Campaign Manager</strong><small>OM</small><small>IT DEPARTMENT</small></div><button class="workspace-drawer-close" type="button" data-bs-dismiss="offcanvas" data-bs-target="#workspaceSidebar" aria-label="Close menu"><svg viewBox="0 0 24 24"><path d="m14 5-7 7 7 7"/></svg></button></div><nav class="workspace-drawer-links" aria-label="Mobile workspace navigation"><a class="workspace-drawer-link is-active" href="#">Chats</a><a class="workspace-drawer-link" href="#">Updates</a><a class="workspace-drawer-link" href="#">Account</a></nav><div class="workspace-sidebar-role">')
    .replace('<a class="mobile-app-brand">', '<div class="mobile-brand-group"><button class="mobile-drawer-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#workspaceSidebar" aria-controls="workspaceSidebar" aria-label="Open menu"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><a class="mobile-app-brand">')
    .replace('</a><div class="mobile-app-tools">', '</a></div><div class="mobile-app-tools">')
    .replace('<div class="workspace-sidebar-footer">Sign out</div>', '<div class="workspace-sidebar-footer"><button class="workspace-sidebar-link">Sign out</button></div>');
const frameEnd = '</main>';
view = view.replace(/<strong>AXA<\/strong>/g, '<strong>IT DEPARTMENT</strong>');
const bootstrap = pathToFileURL(path.join(root,'public/assets/vendor/bootstrap/bootstrap.min.css')).href;
const bootstrapJs = pathToFileURL(path.join(root,'public/assets/vendor/bootstrap/bootstrap.bundle.min.js')).href;
const manifest = JSON.parse(readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const appStyles = pathToFileURL(path.join(root, 'public/build', manifest['resources/css/app.css'].file)).href;
const mock = `window.fetch=async(url,options={})=>({ok:true,json:async()=> options.method ? {} : /\\/42(?:\\?|$)/.test(url) ? ${JSON.stringify(response)} : {data:String(url).includes('scope=department')?[]:[${JSON.stringify({...request, last_message:'Please check inventory.', unread_count:2})}],last_page:1}});`;
writeFileSync(fixture, '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture"><link rel="stylesheet" href="'+bootstrap+'"><link rel="stylesheet" href="'+appStyles+'"><style>body{margin:0;padding:16px;background:#f2f6ff;font-family:Arial,sans-serif}*{box-sizing:border-box}'+css+theme+mobile+'</style></head><body class="workspace-theme workspace-chat">'+frameStart+view+frameEnd+'<script src="'+bootstrapJs+'"></script><script>'+mock+source+'</script></body></html>');
const browser = spawn(chrome, ['--headless','--disable-gpu','--no-sandbox','--no-first-run','--allow-file-access-from-files','--remote-debugging-port=0', '--user-data-dir='+profile,'about:blank'], {windowsHide:true,stdio:'ignore'});
let socket;
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let checks=0;
const check=(condition,message)=>{checks++;assert.ok(condition,message);};
try {
    const portFile=path.join(profile,'DevToolsActivePort');
    for(let i=0;i<100&&!existsSync(portFile);i++) await pause(50);
    const port=readFileSync(portFile,'utf8').split('\n')[0];
    const targets=await(await fetch('http://127.0.0.1:'+port+'/json')).json();
    socket=new WebSocket(targets.find(target=>target.type==='page').webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
    let id=0; const pending=new Map();
    socket.onmessage=({data})=>{const message=JSON.parse(data),task=pending.get(message.id);if(!task)return;pending.delete(message.id);if(message.error)task.reject(Error(message.error.message));else task.resolve(message.result);};
    const call=(method,params={})=>new Promise((resolve,reject)=>{const requestId=++id;pending.set(requestId,{resolve,reject});socket.send(JSON.stringify({id:requestId,method,params}));});
    const evaluate=async expression=>{const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw Error(JSON.stringify(result.exceptionDetails));return result.result.value;};
    await call('Emulation.setFocusEmulationEnabled', {enabled:true});
    for(const width of [320,390,768,1024,1280,1440,1680,1920]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:width===320?640:900,deviceScaleFactor:1,mobile:width<768});
        await call('Page.navigate',{url:pathToFileURL(fixture).href});
        for(let i=0;i<60;i++){if(await evaluate("document.querySelectorAll('.pc-row').length===1"))break;await pause(50);}
        check(await evaluate("document.documentElement.scrollWidth<=innerWidth+1"),'Home has no overflow at '+width);
        check(await evaluate("document.getElementById('procura-chat').getBoundingClientRect().width >= document.querySelector('.app-main').clientWidth-40"), 'Chat fills the main content width at '+width);
        check(await evaluate("document.querySelector('.app-main').getBoundingClientRect().width >= innerWidth-(innerWidth>=992?232:0)-2"), 'Main workspace fills the area beside navigation at '+width);
        check(await evaluate("document.documentElement.scrollHeight<=innerHeight+1"), 'Workspace does not scroll as a page at '+width);
        check(await evaluate("document.querySelector('.workspace-sidebar').scrollHeight<=document.querySelector('.workspace-sidebar').clientHeight+1"), 'Sidebar shell does not scroll');
        if(width<768) check(await evaluate("document.querySelector('.pc-campaign-card strong').getBoundingClientRect().height < 65"), 'Long campaign name wraps into at most two lines');
        if(width<768) {
            await evaluate("document.querySelector('.mobile-drawer-toggle').click()");
            for(let i=0;i<30;i++){if(await evaluate("document.getElementById('workspaceSidebar').classList.contains('show')"))break;await pause(40);}
            check(await evaluate("document.getElementById('workspaceSidebar').classList.contains('show')"), 'Header menu opens the slide-out drawer');
            check(await evaluate("document.querySelector('.workspace-drawer-profile').getBoundingClientRect().width>0 && getComputedStyle(document.querySelector('.workspace-sidebar-links')).display==='none'"), 'Mobile profile and permitted menu replace the desktop links');
            check(await evaluate("document.getElementById('workspaceSidebar').getBoundingClientRect().right<innerWidth && document.querySelector('.offcanvas-backdrop')!==null"), 'Drawer leaves a shaded part of the app visible');
            if(width===390){const shot=await call('Page.captureScreenshot',{format:'png'});writeFileSync(path.join(artifacts,'chat-mobile-drawer-390.png'),Buffer.from(shot.data,'base64'));}
            await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
            await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
            await pause(400);
            check(await evaluate("!document.getElementById('workspaceSidebar').classList.contains('show')"), 'Escape closes the drawer');
            await evaluate("document.querySelector('.mobile-drawer-toggle').click()");
            await pause(400);
            await evaluate("document.querySelector('.workspace-drawer-close').click()");
            await pause(400);
            check(await evaluate("!document.getElementById('workspaceSidebar').classList.contains('show') && document.querySelector('.offcanvas-backdrop')===null"), 'Close button removes drawer and backdrop');
            check(await evaluate("window.scrollY===0"), 'Opening menu does not scroll the app');
            check(await evaluate("getComputedStyle(document.getElementById('pc-campaign-home')).display!=='none'"),'Assigned campaign opens first');
            if(width===390){const shot=await call('Page.captureScreenshot',{format:'png'});writeFileSync(path.join(artifacts,'chat-design-home.png'),Buffer.from(shot.data,'base64'));}
            await evaluate("document.getElementById('pc-open-campaign').click()");
            check(await evaluate("getComputedStyle(document.querySelector('.pc-sidebar')).display!=='none'"),'Campaign opens request list');
        }
        check(await evaluate("!document.getElementById('pc-expand-list') && document.getElementById('procura-chat').classList.contains('pc-compact')"), 'Compact layout is permanent without expansion controls');
        if(width<768) {
            check(await evaluate("document.getElementById('pc-list').getBoundingClientRect().height>=180"), 'Expanded list retains useful scroll space');
            check(await evaluate("document.getElementById('pc-list').getBoundingClientRect().width>=innerWidth-24"), 'Request list uses the phone width');
        }
        if(width<768) check(await evaluate("getComputedStyle(document.querySelector('.mobile-app-heading')).display==='none'"), 'Decorative header is removed');
        if(width===390){const shot=await call('Page.captureScreenshot',{format:'png'});writeFileSync(path.join(artifacts,'chat-design-list-expanded-390.png'),Buffer.from(shot.data,'base64'));}
        if(width<768) {
            check(await evaluate("getComputedStyle(document.getElementById('pc-search-panel')).display==='none'"), 'Search field stays out of the list by default');
            await evaluate("document.querySelector('[data-chat-search-toggle]').click()");
            await pause(80);
            check(await evaluate("getComputedStyle(document.getElementById('pc-search-panel')).display!=='none' && document.activeElement.id==='pc-search'"), 'Header search icon opens and focuses search');
            await evaluate("document.getElementById('pc-search').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");
            check(await evaluate("getComputedStyle(document.getElementById('pc-search-panel')).display==='none' && document.activeElement.matches('[data-chat-search-toggle]')"), 'Escape closes search and restores icon focus');
            check(await evaluate("document.documentElement.scrollWidth<=innerWidth+1"), 'Three header tools fit the phone width');
        }
        check(await evaluate("document.getElementById('pc-scope').value==='screening' && document.getElementById('pc-scope').options.length===2"), 'Menu defaults to screening');
        await evaluate("document.getElementById('pc-scope').value='department';document.getElementById('pc-scope').dispatchEvent(new Event('change',{bubbles:true}))");
        for(let i=0;i<60;i++){if(await evaluate("document.querySelectorAll('.pc-row').length===0"))break;await pause(50);}
        check(await evaluate("document.querySelectorAll('.pc-row').length===0 && document.getElementById('pc-scope').value==='department'"), 'Department requests are separate');
        check(await evaluate("document.documentElement.scrollWidth<=innerWidth+1"), 'Scope menu fits at '+width);
        await evaluate("document.getElementById('pc-scope').value='screening';document.getElementById('pc-scope').dispatchEvent(new Event('change',{bubbles:true}))");
        for(let i=0;i<60;i++){if(await evaluate("document.querySelectorAll('.pc-row').length===1"))break;await pause(50);}
        check(await evaluate("document.querySelectorAll('.pc-row').length===1"), 'Switching to screening restores routed requests');
        await evaluate("document.querySelector('.pc-row').click()");
        for(let i=0;i<60;i++){if(await evaluate("document.querySelectorAll('.pc-message').length===3"))break;await pause(50);}
        check(await evaluate("document.querySelectorAll('.pc-message').length===3"),'Same request history renders');
        check(await evaluate("document.querySelector('[aria-current=step]').textContent.includes('IT Screening')"),'Actual current workflow stage shown');
        check(await evaluate("document.getElementById('pc-request-summary').textContent.includes('125,000')"),'Details show actual amount');
        check(await evaluate("document.documentElement.scrollWidth<=innerWidth+1"),'Conversation has no page overflow at '+width);
        if(width<768) {
            check(await evaluate("getComputedStyle(document.getElementById('pc-workflow')).display==='none' && getComputedStyle(document.querySelector('.pc-inspector')).display==='none'"), 'Phone prioritizes messages until request info is opened');
            await evaluate("window.expandedMessageHeight=document.getElementById('pc-messages').clientHeight;document.getElementById('pc-context-toggle').click()");
            check(await evaluate("document.getElementById('pc-context-toggle').getAttribute('aria-expanded')==='true' && getComputedStyle(document.querySelector('.pc-inspector')).display!=='none'"), 'Info button reveals workflow and details');
            check(await evaluate("window.expandedMessageHeight-document.getElementById('pc-messages').clientHeight>=80"), 'Hiding context gives messages at least eighty extra pixels');
        }
        if(width>=1400) {
            check(await evaluate("getComputedStyle(document.querySelector('.pc-thread-body')).gridTemplateColumns.split(' ').length===2"),'Desktop details beside messages');
            check(await evaluate("document.querySelector('.pc-inspector').getBoundingClientRect().left >= document.querySelector('.pc-discussion').getBoundingClientRect().right - 1"), 'Desktop inspector stays on the right');
        } else {
            check(await evaluate("document.querySelector('.pc-inspector').getBoundingClientRect().bottom <= document.getElementById('pc-messages').getBoundingClientRect().top"), 'Request information sits above messages');
            check(await evaluate("[...document.querySelectorAll('.pc-info-card summary')].every(summary=>summary.getBoundingClientRect().height>=44)"), 'Accordions have touch sized headings');
            await evaluate("document.querySelector('.pc-info-card summary').click()");
            check(await evaluate("document.querySelector('.pc-info-card').open && document.getElementById('pc-request-summary').getBoundingClientRect().height > 0"), 'Request details expand');
            await evaluate("document.querySelector('.pc-info-card summary').click()");
            check(await evaluate("!document.querySelector('.pc-info-card').open"), 'Request details collapse');
            check(await evaluate("document.getElementById('pc-compose').getBoundingClientRect().top >= document.getElementById('pc-messages').getBoundingClientRect().bottom"), 'Composer follows messages');
        }
        if(width<768) {
            await evaluate("document.getElementById('pc-context-toggle').click()");
            check(await evaluate("document.getElementById('pc-context-toggle').getAttribute('aria-expanded')==='false' && getComputedStyle(document.querySelector('.pc-inspector')).display==='none'"), 'Info closes and restores message space');
        }
        await evaluate("document.getElementById('pc-body').value='Unsent draft'");
        if(width<768) {
            check(await evaluate("document.getElementById('pc-sidebar-toggle').getAttribute('aria-label')==='View request list'"), 'Mobile conversation offers View sidebar');
            await evaluate("document.getElementById('pc-sidebar-toggle').click()");
            check(await evaluate("getComputedStyle(document.querySelector('.pc-sidebar')).display!=='none' && document.getElementById('pc-sidebar-toggle').getAttribute('aria-label')==='Hide request list'"), 'View sidebar restores request list');
            check(await evaluate("document.getElementById('pc-body').value==='Unsent draft'"), 'Showing sidebar preserves draft');
            await evaluate("document.getElementById('pc-sidebar-toggle').click()");
            check(await evaluate("getComputedStyle(document.querySelector('.pc-conversation')).display!=='none' && document.querySelectorAll('.pc-message').length===3"), 'Hiding sidebar returns to the same conversation');
        } else {
            const conversationWidth = await evaluate("document.querySelector('.pc-conversation').getBoundingClientRect().width");
            await evaluate("document.getElementById('pc-sidebar-toggle').click()");
            check(await evaluate("getComputedStyle(document.querySelector('.pc-sidebar')).display==='none'"), 'Desktop sidebar hides');
            check(await evaluate("document.querySelector('.pc-conversation').getBoundingClientRect().width") > conversationWidth + 100, 'Desktop conversation widens');
            await evaluate("document.getElementById('pc-sidebar-toggle').click()");
        }
        check(await evaluate("document.getElementById('pc-body').value==='Unsent draft'"), 'Sidebar toggle never clears draft');
        await evaluate("document.getElementById('pc-body').value=''");
        if(width===390||width===1680){const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});writeFileSync(path.join(artifacts,'chat-design-'+width+'.png'),Buffer.from(shot.data,'base64'));}
        const composerTop = await evaluate("document.getElementById('pc-compose').getBoundingClientRect().top");
        await evaluate("const log=document.getElementById('pc-messages'); for(let i=0;i<20;i++)log.append(log.firstElementChild.cloneNode(true));log.scrollTop=log.scrollHeight;window.scrollTo(0,9999)");
        check(await evaluate("window.scrollY===0 && document.getElementById('pc-messages').scrollTop>0"), 'Only the message pane scrolls at '+width+' '+JSON.stringify(await evaluate("({window:scrollY,top:document.getElementById('pc-messages').scrollTop,client:document.getElementById('pc-messages').clientHeight,scroll:document.getElementById('pc-messages').scrollHeight})")));
        check(Math.abs(composerTop - await evaluate("document.getElementById('pc-compose').getBoundingClientRect().top"))<1, 'Composer does not move with message scrolling');
        check(await evaluate("!document.querySelector('.mobile-bottom-navigation')"), 'Bottom navigation is removed');
        check(await evaluate("document.getElementById('pc-compose').getBoundingClientRect().bottom<=innerHeight+1"), 'Composer stays inside the available viewport');
        if(width<768){
            await evaluate("document.getElementById('pc-body').focus()");
            await call('Emulation.setDeviceMetricsOverride',{width,height:500,deviceScaleFactor:1,mobile:true});
            await pause(100);
            check(await evaluate("document.documentElement.scrollHeight<=innerHeight+1 && document.getElementById('pc-compose').getBoundingClientRect().bottom<=innerHeight"), 'Short viewport keeps composer visible at '+width+' '+JSON.stringify(await evaluate("({focused:document.getElementById('pc-body').matches(':focus'),docFocus:document.hasFocus(),active:document.activeElement.id,toolbar:getComputedStyle(document.querySelector('.pc-sidebar-toolbar')).display,w:innerWidth,viewport:visualViewport.height,composer:document.getElementById('pc-compose').getBoundingClientRect().bottom})")));
            await evaluate("document.getElementById('pc-sidebar-toggle').click()");
            check(await evaluate("getComputedStyle(document.querySelector('.pc-sidebar')).display!=='none'"), 'Persistent toggle remains available after typing');
            check(await evaluate("!document.getElementById('pc-back') && !document.getElementById('pc-campaign-back')"), 'Old Back buttons are removed');
            check(await evaluate("getComputedStyle(document.querySelector('.mobile-app-heading')).display==='none'"), 'Compact header remains unchanged');
        }
    }
    console.log('Procura Chat design: '+checks+' browser checks passed.');
} finally {
    socket?.close();browser.kill();
    await new Promise(resolve=>{if(browser.exitCode!==null)resolve();else browser.once('exit',resolve);});
    assert.ok(path.resolve(profile).startsWith(path.resolve(artifacts)+path.sep));
    rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:300});
}
