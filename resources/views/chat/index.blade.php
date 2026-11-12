@extends('layouts.app')
@section('title', 'Procura Chats')
@section('content')
<div id="procura-chat" data-base="{{ route('chat.requests') }}" data-user="{{ auth()->id() }}" data-name="{{ auth()->user()->name }}"
     data-om-approve="{{ route('om-approvals.approve', '__ID__') }}" data-om-return="{{ route('om-approvals.return', '__ID__') }}"
     data-president="{{ route('president-approvals.supplier.decision', '__ID__') }}" data-request="{{ request('request') }}">
    <header class="pc-page-header">
        <div><span class="pc-eyebrow">ASI PROCURA &middot; WORKSPACE</span><h1>Procura Chats</h1><p>One request. One conversation. Every step connected.</p></div>
        <span class="pc-connection" id="pc-connection" role="status">Connecting...</span>
    </header>
    <div class="pc-error" id="pc-error" role="alert" hidden></div>
    <div class="pc-shell">
        <aside class="pc-sidebar" aria-label="Request conversations">
            <label class="pc-search"><span aria-hidden="true">&#8981;</span><input id="pc-search" type="search" placeholder="Search request number or title..." aria-label="Search conversations"></label>
            <div class="pc-filters" role="group" aria-label="Conversation filters">
                <button type="button" data-filter="all" class="active" aria-pressed="true">All</button>
                <button type="button" data-filter="unread" aria-pressed="false">Unread</button>
                <button type="button" data-filter="mine" aria-pressed="false">My requests</button>
                <button type="button" data-filter="approval" aria-pressed="false">For approval</button>
            </div>
            <div id="pc-list" class="pc-list"></div>
            <nav class="pc-pagination" aria-label="Conversation pages"><button id="pc-prev" type="button">Previous</button><span id="pc-page"></span><button id="pc-next" type="button">Next</button></nav>
        </aside>
        <section class="pc-conversation" aria-label="Conversation">
            <div id="pc-empty" class="pc-empty"><span class="pc-empty-icon">&#8599;</span><h2>Keep your requests moving</h2><p>Choose a request to see updates, discuss details, and review approvals.</p></div>
            <div id="pc-thread" hidden>
                <header class="pc-thread-header">
                    <button id="pc-back" type="button" class="pc-back" aria-label="Back to conversations">&#8592;</button>
                    <div class="pc-request-icon">PR</div>
                    <div class="pc-thread-heading"><h2 id="pc-title"></h2><p id="pc-subtitle"></p></div>
                    <span class="pc-stage" id="pc-stage"></span>
                </header>
                <details class="pc-details"><summary>Request details &amp; supplier quotations</summary><div id="pc-details-content"></div></details>
                <div id="pc-actions" class="pc-actions"></div>
                <div id="pc-messages" class="pc-messages" role="log" aria-label="Messages"></div>
                <form id="pc-compose" class="pc-compose">
                    <label class="pc-attach" title="Attach PDF or image">&#43;<input data-chat-attachment id="pc-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" aria-label="Attach PDF or image"></label>
                    <div class="pc-compose-input"><textarea id="pc-body" rows="1" maxlength="5000" placeholder="Type a message..." aria-label="Message"></textarea><span id="pc-file-name"></span></div>
                    <button type="submit" id="pc-send">Send &#8599;</button>
                </form>
                <p id="pc-readonly" class="pc-readonly" hidden>You can read this conversation. Messaging is available to the requestor and current or previous handler.</p>
            </div>
        </section>
    </div>
    <dialog id="pc-decision" class="pc-dialog" aria-labelledby="pc-decision-title">
        <form id="pc-decision-form">
            <h2 id="pc-decision-title">Confirm decision</h2>
            <p>Review the request details before signing as <strong>{{ auth()->user()->name }}</strong>.</p>
            <label for="pc-remarks">Remarks <span id="pc-required"></span></label><textarea id="pc-remarks" rows="3" maxlength="2000"></textarea>
            <label for="pc-signature">Draw your signature</label>
            <canvas id="pc-signature" width="600" height="180" aria-label="Signature drawing area"></canvas>
            <button id="pc-clear" type="button" class="pc-clear">Clear signature</button>
            <p id="pc-decision-error" role="alert"></p>
            <div class="pc-dialog-footer"><button id="pc-cancel" type="button">Cancel</button><button type="submit" id="pc-confirm">Sign &amp; confirm</button></div>
        </form>
    </dialog>
</div>
@endsection
