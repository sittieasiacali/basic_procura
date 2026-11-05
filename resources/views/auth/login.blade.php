<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#160D76">
    <title>Sign in &middot; {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/images/asi-coin.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/images/asi-coin.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="auth-page" style="--auth-building-image: url('{{ asset('assets/images/Allianz.png') }}');">
    <aside class="auth-brand-panel" aria-hidden="true">
        <div class="auth-brand-photo"></div>
        <p class="auth-brand-company">Allianz-Synergia Inc.</p>

        @php
            // Purchase request lifecycle, in PurchaseRequestWorkflow order.
            $flowSteps = [
                ['name' => 'Request', 'status' => 'Submitted', 'tone' => 'blue',
                    'text' => 'A requester files a purchase request with the items, quantities and purpose.'],
                ['name' => 'IT / Premises screening', 'status' => 'Screening', 'tone' => 'indigo',
                    'text' => 'Existing inventory is checked first, so nothing is bought that is already in stock.'],
                ['name' => 'Canvassing', 'status' => 'Canvassing', 'tone' => 'indigo',
                    'text' => 'Supplier quotations are gathered and compared on price, terms and delivery.'],
                ['name' => 'President approval', 'status' => 'For approval', 'tone' => 'orange',
                    'text' => 'The President reviews the canvass and signs off on the purchase.'],
                ['name' => 'Purchase order', 'status' => 'PO issued', 'tone' => 'blue',
                    'text' => 'A purchase order is prepared, signed and issued to the chosen supplier.'],
                ['name' => 'Budget request', 'status' => 'Funding', 'tone' => 'orange',
                    'text' => 'A budget request releases the funds that pay for the order.'],
                ['name' => 'Receiving', 'status' => 'Received', 'tone' => 'green',
                    'text' => 'Deliveries are inspected and received against the purchase order.'],
                ['name' => 'Add to inventory', 'status' => 'Stocked', 'tone' => 'green',
                    'text' => 'Received items are added to inventory and the request is closed.'],
            ];
        @endphp

        <div class="auth-brand-body">
            <div class="auth-brand-statement">
                <p class="auth-brand-eyebrow">Procurement, made accountable</p>
                <h2>Every peso. Every signature.<br><span class="auth-brand-highlight">One record.</span></h2>
                <span class="auth-brand-rule"></span>
                <p>From first request to stockroom shelf, in one accountable flow.</p>
            </div>

            {{-- Animated vertical timeline. Generic workflow text only: this page is public. --}}
            <div class="auth-flow" data-auth-flow>
                <div class="auth-flow-header">
                    <p class="auth-flow-title">Request lifecycle</p>
                    <p class="auth-flow-counter"><span data-flow-count>01</span> / {{ str_pad(count($flowSteps), 2, '0', STR_PAD_LEFT) }}</p>
                </div>
                <ol class="auth-flow-track">
                    @foreach($flowSteps as $flowStep)
                        <li class="auth-flow-step" data-flow-step>
                            <span class="auth-flow-node">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <div class="auth-flow-body">
                                <div class="auth-flow-head">
                                    <span class="auth-flow-name">{{ $flowStep['name'] }}</span>
                                    <span class="auth-flow-status" data-tone="{{ $flowStep['tone'] }}">{{ $flowStep['status'] }}</span>
                                </div>
                                <div class="auth-flow-detail">
                                    <div class="auth-flow-detail-inner">
                                        <p>{{ $flowStep['text'] }}</p>
                                    </div>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </aside>

    <div class="auth-form-side">
        <div class="auth-ambient" aria-hidden="true">
            <span class="auth-orb auth-orb--blue"></span>
            <span class="auth-orb auth-orb--orange"></span>
            <span class="auth-orb auth-orb--indigo"></span>
        </div>

        <section class="auth-form-panel" aria-labelledby="sign-in-title">
            {{-- Decorative status chips that float beside the card on wide screens. --}}
            <div class="auth-chip auth-chip--approved" aria-hidden="true">
                <span class="auth-chip-icon auth-chip-icon--success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <span class="auth-chip-text"><strong>Request approved</strong><small>All signatures complete</small></span>
            </div>
            <div class="auth-chip auth-chip--paid" aria-hidden="true">
                <span class="auth-chip-icon auth-chip-icon--accent">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/><path d="m7.5 4.27 9 5.15M3.29 7 12 12l8.71-5M12 22V12"/></svg>
                </span>
                <span class="auth-chip-text"><strong>Delivery received</strong><small>Logged to inventory</small></span>
            </div>

            <div class="auth-badge" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path class="auth-badge-shackle" d="M7 11V7a5 5 0 0 1 10 0v4" stroke="var(--asi-navy)"/>
                    <rect x="3.5" y="11" width="17" height="11" rx="2.5" fill="var(--asi-orange)" stroke="var(--asi-orange)"/>
                    <circle cx="12" cy="15.6" r="1.6" fill="#fffffa" stroke="none"/>
                    <path d="M12 16.4v2.2" stroke="#fffffa" stroke-width="1.8"/>
                </svg>
            </div>

            <img class="auth-logo" src="{{ asset('assets/images/asi-procura-logo-login.png') }}" alt="ASI PROCURA" width="184" height="61">
            <h1 id="sign-in-title">Welcome <span class="auth-heading-accent">back</span></h1>
            <p class="auth-subtitle">Sign in to continue to ASI PROCURA.</p>

            @if($errors->any())
                <div class="auth-alert" id="auth-alert" role="alert">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" novalidate data-auth-form>
                @csrf
                <div class="auth-field">
                    <label class="auth-label" for="email">Email or username</label>
                    <div class="auth-control">
                        <span class="auth-field-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="text" value="{{ old('email') }}" placeholder="name@company.com" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" @error('email') aria-invalid="true" aria-describedby="{{ $message === $errors->first() ? 'auth-alert' : 'email-error' }}" @enderror>
                    </div>
                    {{-- The banner already shows the first error; only repeat a different one here. --}}
                    @error('email')@if($message !== $errors->first())<div class="auth-field-error" id="email-error">{{ $message }}</div>@endif @enderror
                </div>

                <div class="auth-field">
                    <label class="auth-label" for="password">Password</label>
                    <div class="auth-control auth-control--password">
                        <span class="auth-field-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" placeholder="Enter your password" required autocomplete="current-password" aria-describedby="caps-hint @error('password') {{ $message === $errors->first() ? 'auth-alert' : 'password-error' }} @enderror" @error('password') aria-invalid="true" @enderror>
                        <button class="auth-password-toggle" type="button" aria-controls="password" aria-pressed="false" aria-label="Show password" data-password-toggle>
                            <svg class="auth-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="auth-eye-off" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.7 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-2.2 3.1M6.6 6.6A16.6 16.6 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2M2 2l20 20"/></svg>
                        </button>
                    </div>
                    <div class="auth-caps-hint" id="caps-hint" role="status" hidden>Caps Lock is on.</div>
                    @error('password')@if($message !== $errors->first())<div class="auth-field-error" id="password-error">{{ $message }}</div>@endif @enderror
                </div>

                <div class="form-check auth-remember">
                    <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1" @checked(old('remember'))>
                    <label class="form-check-label" for="remember">Keep me signed in on this device</label>
                </div>

                <button class="btn btn-primary auth-submit" type="submit" data-auth-submit>
                    <span class="spinner-border" aria-hidden="true"></span>
                    <span data-auth-submit-label>Sign in</span>
                    <svg class="auth-submit-arrow" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </button>
            </form>

            <p class="auth-help">Authorized personnel only. For access or password concerns, contact your system administrator.</p>
        </section>

        <footer class="auth-legal">
            <span>&copy; {{ now()->year }} Allianz-Synergia Inc.</span>
            <span>ASI PROCURA</span>
        </footer>
    </div>
</main>
<script>
    (() => {
        const page = document.querySelector('.auth-page');
        const password = document.getElementById('password');
        const toggle = document.querySelector('[data-password-toggle]');
        const capsHint = document.getElementById('caps-hint');
        const form = document.querySelector('[data-auth-form]');
        const submit = document.querySelector('[data-auth-submit]');

        toggle?.addEventListener('click', () => {
            const show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', String(show));
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            // The badge padlock opens while the password is visible.
            page.classList.toggle('is-revealing', show);
            password.focus();
        });

        const updateCaps = (event) => {
            if (typeof event.getModifierState === 'function') {
                capsHint.hidden = ! event.getModifierState('CapsLock');
            }
        };
        password?.addEventListener('keydown', updateCaps);
        password?.addEventListener('keyup', updateCaps);
        password?.addEventListener('blur', () => { capsHint.hidden = true; });

        form?.addEventListener('submit', () => {
            if (submit.disabled) {
                return;
            }
            password.type = 'password';
            page.classList.remove('is-revealing');
            page.classList.add('is-submitting');
            submit.classList.add('is-loading');
            submit.querySelector('[data-auth-submit-label]').textContent = 'Signing in…';
            // Disable after the submit has been dispatched so the request still goes out.
            setTimeout(() => { submit.disabled = true; }, 0);
        });

        // Re-enable if the page is restored from the back/forward cache.
        window.addEventListener('pageshow', () => {
            submit.disabled = false;
            submit.classList.remove('is-loading');
            page.classList.remove('is-submitting');
            submit.querySelector('[data-auth-submit-label]').textContent = 'Sign in';
        });

        // System flow walkthrough on the brand panel.
        const flow = document.querySelector('[data-auth-flow]');
        if (! flow) {
            return;
        }
        const steps = Array.from(flow.querySelectorAll('[data-flow-step]'));
        const count = flow.querySelector('[data-flow-count]');
        const last = steps.length - 1;
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Index past the last step shows the closed state (every step done).
        const show = (index) => {
            const complete = index > last;
            steps.forEach((step, i) => {
                step.classList.toggle('is-done', complete || i < index);
                step.classList.toggle('is-active', ! complete && i === index);
            });
            flow.classList.toggle('is-complete', complete);
            count.textContent = String(complete ? steps.length : index + 1).padStart(2, '0');
        };

        if (reduceMotion) {
            show(steps.length);
            return;
        }

        let current = 0;
        show(current);
        const advance = () => {
            current = (current + 1) % (steps.length + 1);
            if (current === 0) {
                // Jump back to the start without animating the connectors backwards.
                flow.classList.add('is-resetting');
                show(0);
                requestAnimationFrame(() => requestAnimationFrame(() => flow.classList.remove('is-resetting')));
            } else {
                show(current);
            }
            setTimeout(advance, current === steps.length ? 3600 : 2400);
        };
        setTimeout(advance, 2400);
    })();
</script>
</body>
</html>
