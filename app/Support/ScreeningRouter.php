<?php

namespace App\Support;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;

/**
 * Decides at submission whether a request can skip the shared IT / Premises queue.
 * A request goes straight to IT screening only when every item reads as IT equipment;
 * anything mixed or unclear stays in the shared queue for a person to classify.
 */
class ScreeningRouter
{
    /**
     * Whole-word / phrase matches, case-insensitive. Deliberately specific: words like
     * "switch", "cable" or "pc" also describe premises items, so only their IT forms are listed.
     */
    public const IT_KEYWORDS = [
        'laptop', 'notebook computer', 'macbook', 'chromebook', 'desktop', 'computer', 'cpu', 'workstation pc',
        'monitor', 'keyboard', 'mouse', 'webcam', 'headset', 'docking station', 'ipad',
        'printer', 'toner', 'ink cartridge', 'scanner',
        'router', 'network switch', 'access point', 'modem', 'firewall', 'server', 'nas', 'wifi', 'wi-fi',
        'ethernet', 'lan cable', 'utp cable', 'hdmi', 'usb', 'ups',
        'ssd', 'hdd', 'hard drive', 'flash drive', 'ram', 'memory module',
        'software', 'license', 'licence', 'antivirus', 'microsoft office',
    ];

    /**
     * IT keywords found in the request, or [] when it should stay in the shared queue.
     *
     * @return list<string>
     */
    public function itMatches(PurchaseRequest $purchaseRequest): array
    {
        $items = $purchaseRequest->items()->get(['id', 'description', 'brand_specifications']);
        if ($items->isEmpty()) {
            return [];
        }

        $matches = [];
        foreach ($items as $item) {
            $found = $this->keywordsIn($item);
            if ($found === []) {
                return [];
            }
            array_push($matches, ...$found);
        }

        return array_values(array_unique($matches));
    }

    /** @return list<string> */
    private function keywordsIn(PurchaseRequestItem $item): array
    {
        $text = mb_strtolower($item->description.' '.$item->brand_specifications);

        return array_values(array_filter(
            self::IT_KEYWORDS,
            fn (string $keyword) => preg_match('/(?<![\p{L}\p{N}])'.preg_quote($keyword, '/').'s?(?![\p{L}\p{N}])/u', $text) === 1,
        ));
    }
}
