@php
    $navy = '#160d76';
    $orange = '#f08530';
    $ink = '#222222';
    $muted = '#667085';
    $border = '#DCE2E8';

    // Logos are attached inline (CID) so they show even when PROCURA's URL is not
    // reachable by the recipient. Outside a real send there is no $message, so
    // previews fall back to a data URI.
    $logos = [];
    foreach (['asi' => 'asi-procura-logo.png', 'allianz' => 'allianz-synergia-logo.png'] as $key => $file) {
        $path = resource_path('images/email/'.$file);
        $logos[$key] = isset($message)
            ? $message->embed($path)
            : 'data:image/png;base64,'.base64_encode(file_get_contents($path));
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $requestNumber }} - {{ $stage }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .outer { padding: 12px 8px !important; }
            .card { border-radius: 10px !important; }
            .px { padding-left: 18px !important; padding-right: 18px !important; }
            .logo-asi { width: 150px !important; }
            .logo-allianz { width: 92px !important; }
            .req-number { font-size: 24px !important; }
            .kv-label, .kv-value { display: block !important; width: auto !important; }
            .kv-label { padding: 12px 14px 2px !important; font-size: 12px !important; text-transform: uppercase; letter-spacing: 0.5px; }
            .kv-value { padding: 0 14px 12px !important; }
            .quote-main, .quote-total { display: block !important; width: auto !important; }
            .quote-total { text-align: left !important; padding-top: 0 !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#f2f4f8; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:{{ $ink }}; -webkit-text-size-adjust:100%;">
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ $requestNumber }} is now at {{ $stage }}.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f4f8;">
    <tr>
        <td align="center" class="outer" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="card" style="max-width:600px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 18px rgba(22,13,118,0.08);">

                {{-- Header --}}
                <tr>
                    <td class="px" style="background:#ffffff; padding:20px 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td valign="middle">
                                    <img src="{{ $logos['asi'] }}" width="200" alt="ASI PROCURA" class="logo-asi" style="display:block; width:200px; max-width:100%; height:auto; border:0; font-size:20px; font-weight:800; color:{{ $navy }};">
                                </td>
                                <td align="right" valign="middle">
                                    <img src="{{ $logos['allianz'] }}" width="120" alt="Allianz Synergia" class="logo-allianz" style="display:block; width:120px; max-width:100%; height:auto; border:0; font-size:13px; font-weight:700; color:{{ $navy }};">
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr><td style="height:3px; background:{{ $orange }}; line-height:3px; font-size:0;">&nbsp;</td></tr>
                <tr>
                    <td class="px" style="background:{{ $navy }}; padding:8px 32px; font-size:11px; font-weight:700; color:#c9c5f0; text-transform:uppercase; letter-spacing:1.5px;">
                        Workflow update
                    </td>
                </tr>

                {{-- Hero --}}
                <tr>
                    <td class="px" style="padding:28px 32px 4px;">
                        <p style="margin:0 0 6px; font-size:13px; color:{{ $muted }}; text-transform:uppercase; letter-spacing:1px;">Request number</p>
                        <p class="req-number" style="margin:0 0 16px; font-size:28px; font-weight:800; color:{{ $navy }};">{{ $requestNumber }}</p>
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="background:#fff3e8; border:1px solid #f9cfa8; border-radius:999px; padding:7px 16px; font-size:13px; font-weight:700; color:#b5571a;">
                                    &#9679;&nbsp; Current stage: {{ $stage }}
                                </td>
                            </tr>
                        </table>
                        <p style="margin:18px 0 0; font-size:15px; line-height:1.6; color:{{ $ink }};">
                            Hello, the workflow for a request from your assigned Team Leader has been updated.
                        </p>
                    </td>
                </tr>

                {{-- Request details (initial submission only) --}}
                @if ($requestDetails !== [])
                    <tr>
                        <td class="px" style="padding:24px 32px 0;">
                            <p style="margin:0 0 10px; font-size:13px; font-weight:700; color:{{ $navy }}; text-transform:uppercase; letter-spacing:1px;">Request details</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $border }}; border-radius:8px; border-collapse:separate;">
                                @foreach ($requestDetails as $label => $value)
                                    <tr>
                                        <td width="38%" class="kv-label" style="padding:10px 14px; font-size:13px; color:{{ $muted }}; border-top:{{ $loop->first ? '0' : '1px solid '.$border }}; vertical-align:top;">{{ $label }}</td>
                                        <td class="kv-value" style="padding:10px 14px; font-size:14px; color:{{ $ink }}; font-weight:600; line-height:1.5; border-top:{{ $loop->first ? '0' : '1px solid '.$border }};">{{ $value }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td width="38%" class="kv-label" style="padding:10px 14px; font-size:13px; color:{{ $muted }}; border-top:1px solid {{ $border }}; vertical-align:top;">Requested items</td>
                                    <td class="kv-value" style="padding:10px 14px; font-size:14px; color:{{ $ink }}; line-height:1.5; border-top:1px solid {{ $border }};">
                                        @foreach ($items as $item)
                                            <div style="padding:2px 0;">&#8226; {{ $item }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

                {{-- Processing details --}}
                @if ($processDetails !== [])
                    <tr>
                        <td class="px" style="padding:24px 32px 0;">
                            <p style="margin:0 0 10px; font-size:13px; font-weight:700; color:{{ $navy }}; text-transform:uppercase; letter-spacing:1px;">Processing details</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8f9fc; border-left:4px solid {{ $navy }}; border-radius:6px;">
                                @foreach ($processDetails as $detail)
                                    <tr>
                                        <td width="38%" class="kv-label" style="padding:10px 14px; font-size:13px; color:{{ $muted }}; vertical-align:top;">{{ $detail['label'] }}</td>
                                        <td class="kv-value" style="padding:10px 14px; font-size:14px; color:{{ $ink }}; font-weight:600; line-height:1.5;">{{ $detail['value'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif

                {{-- Quotations --}}
                @if ($quotations !== [])
                    <tr>
                        <td class="px" style="padding:24px 32px 0;">
                            <p style="margin:0 0 10px; font-size:13px; font-weight:700; color:{{ $navy }}; text-transform:uppercase; letter-spacing:1px;">Quotations</p>
                            @foreach ($quotations as $quote)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $quote['highlight'] ? '#f9cfa8' : $border }}; background:{{ $quote['highlight'] ? '#fffaf5' : '#ffffff' }}; border-radius:8px; margin-bottom:10px;">
                                    <tr>
                                        <td class="quote-main" style="padding:14px 16px; vertical-align:top;">
                                            <div style="font-size:15px; font-weight:700; color:{{ $ink }};">{{ $quote['supplier'] }}</div>
                                            @if ($quote['vat'])
                                                <div style="font-size:12px; color:{{ $muted }}; padding-top:2px; white-space:nowrap;">{{ $quote['vat'] }}</div>
                                            @endif
                                            @foreach ($quote['tags'] as $tag)
                                                <span style="display:inline-block; margin-top:8px; margin-right:4px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; background:{{ $tag['bg'] }}; color:{{ $tag['color'] }};">{{ $tag['text'] }}</span>
                                            @endforeach
                                        </td>
                                        @if ($quote['amount'])
                                            <td align="right" class="quote-total" style="padding:14px 16px; white-space:nowrap; vertical-align:top;">
                                                <div style="font-size:11px; color:{{ $muted }}; text-transform:uppercase; letter-spacing:1px;">Total</div>
                                                <div style="font-size:18px; font-weight:800; color:{{ $navy }};">{{ $quote['amount'] }}</div>
                                            </td>
                                        @endif
                                    </tr>
                                </table>
                            @endforeach
                        </td>
                    </tr>
                @endif

                {{-- Request workflow tracker --}}
                @if ($workflowStep !== null)
                    <tr>
                        <td class="px" style="padding:24px 32px 0;">
                            <p style="margin:0 0 10px; font-size:13px; font-weight:700; color:{{ $navy }}; text-transform:uppercase; letter-spacing:1px;">Request workflow</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $border }}; border-radius:10px; background:#ffffff;">
                                <tr><td style="padding:16px 18px 6px;">
                                    @foreach ($workflowSteps as $i => $label)
                                        @php
                                            $number = $i + 1;
                                            $done = $number < $workflowStep;
                                            $current = $number === $workflowStep;
                                            $dotBg = $done ? '#237a57' : ($current ? $orange : '#ffffff');
                                            $dotBorder = $done ? '#237a57' : ($current ? $orange : '#c5ccd6');
                                            $dotColor = $done || $current ? '#ffffff' : '#98a2b3';
                                            $lineColor = $done ? '#237a57' : '#e1e5eb';
                                        @endphp
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td width="34" valign="top" align="center">
                                                    <div style="width:28px; height:28px; line-height:28px; border-radius:50%; background:{{ $dotBg }}; border:2px solid {{ $dotBorder }}; color:{{ $dotColor }}; font-size:13px; font-weight:800; text-align:center; box-sizing:border-box; {{ $current ? 'box-shadow:0 0 0 4px #fde3cc;' : '' }}">{!! $done ? '&#10003;' : $number !!}</div>
                                                    @unless ($loop->last)
                                                        <div style="width:2px; height:18px; margin:2px auto; background:{{ $lineColor }};"></div>
                                                    @endunless
                                                </td>
                                                <td valign="top" style="padding:4px 0 0 12px;">
                                                    <div style="font-size:14px; font-weight:{{ $current ? '800' : '600' }}; color:{{ $current ? $ink : ($done ? '#344054' : '#98a2b3') }};">{{ $label }}</div>
                                                    @if ($current)
                                                        <div style="font-size:12px; color:#b5571a; font-weight:700; padding-top:2px;">Now: {{ $stage }}</div>
                                                    @endif
                                                </td>
                                                <td align="right" valign="top" style="padding-top:6px; font-size:11px; font-weight:700; white-space:nowrap; color:{{ $done ? '#237a57' : ($current ? '#b5571a' : '#98a2b3') }};">
                                                    {{ $done ? 'Done' : ($current ? 'In progress' : '') }}
                                                </td>
                                            </tr>
                                        </table>
                                    @endforeach
                                    @if ($workflowStep > count($workflowSteps))
                                        <div style="margin:6px 0 10px; padding:8px 12px; border-radius:6px; background:#e3f4ec; color:#237a57; font-size:13px; font-weight:700; text-align:center;">&#10003; All workflow steps completed</div>
                                    @endif
                                </td></tr>
                            </table>
                        </td>
                    </tr>
                @endif
                <tr>
                    <td class="px" style="padding:12px 32px 28px; font-size:13px; line-height:1.6; color:{{ $muted }}; text-align:center;">
                        This is an informational update. No management review of the purchase request is required.
                    </td>
                </tr>
            </table>
            <p style="margin:16px 0 0; font-size:11px; color:#98a2b3;">&copy; {{ date('Y') }} {{ config('app.name') }} &middot; Automated notification</p>
        </td>
    </tr>
</table>
</body>
</html>
