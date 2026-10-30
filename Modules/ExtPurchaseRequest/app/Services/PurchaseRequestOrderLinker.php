<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Services;

use Modules\ExtPurchaseRequest\Enums\PurchaseRequestStatus;
use Modules\ExtPurchaseRequest\Models\PurchaseRequest;
use Modules\ExtPurchaseRequest\Models\PurchaseRequestItem;
use Modules\ExtPurchaseRequest\Models\PurchaseRequestOrderLink;
use Modules\Purchase\Exceptions\PurchaseRuleException;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * Menjaga hubungan item PR <-> item PO beserta pembukuan qty yang sudah dipesan.
 *
 * Dipisahkan dari PurchaseRequestService agar TIDAK ada ketergantungan melingkar.
 * Semua method WAJIB dipanggil di dalam DB::transaction() pemanggilnya.
 */
class PurchaseRequestOrderLinker
{
    /**
     * Hubungkan item PR ke item PO: qty dipesan item PR bertambah, link dibuat, status PR dihitung ulang.
     *
     * @param list<array{purchase_request_item_id:int, purchase_order_item_id:int, base_quantity:string}> $allocations
     */
    public function link(array $allocations): void
    {
        if ($allocations === []) {
            return;
        }

        $itemIds = array_values(array_unique(array_map(static fn (array $a): int => (int) $a['purchase_request_item_id'], $allocations)));
        [$requests, $items] = $this->lockByItemIds($itemIds);

        // Total qty yang diminta per item PR (satu item PR dapat dipecah ke beberapa baris PO).
        $sum = [];
        foreach ($allocations as $a) {
            $id = (int) $a['purchase_request_item_id'];
            $sum[$id] = bcadd($sum[$id] ?? '0', (string) $a['base_quantity'], 4);
        }

        foreach ($sum as $itemId => $qty) {
            /** @var PurchaseRequestItem $item */
            $item = $items->get($itemId);
            $request = $requests->get($item->purchase_request_id);

            if (! $request->status->canConvert()) {
                throw new PurchaseRuleException("PR {$request->document_number} tidak dapat dipesan (status: {$request->status->label()}).");
            }

            $new = bcadd((string) $item->ordered_base_quantity, $qty, 4);
            if (bccomp($new, (string) $item->base_quantity, 4) > 0) {
                throw new PurchaseRuleException("Qty yang dipesan melebihi sisa permintaan pada PR {$request->document_number}.");
            }

            $item->forceFill(['ordered_base_quantity' => $new])->save();
        }

        foreach ($allocations as $a) {
            PurchaseRequestOrderLink::query()->create([
                'purchase_request_item_id' => (int) $a['purchase_request_item_id'],
                'purchase_order_item_id' => (int) $a['purchase_order_item_id'],
                'base_quantity' => (string) $a['base_quantity'],
            ]);
        }

        $this->syncStatuses($requests->keys()->all());
    }

    /** PO ini dibuat dari PR (memiliki link)? */
    public function hasLinks(PurchaseOrder $order): bool
    {
        return PurchaseRequestOrderLink::query()
            ->whereIn('purchase_order_item_id', $order->items()->pluck('id'))
            ->exists();
    }

    /**
     * Lepas seluruh link PO ini (PO draft dihapus / PO dibatalkan): qty dikembalikan menjadi
     * sisa PR, link dihapus, dan status PR dihitung ulang.
     */
    public function release(PurchaseOrder $order): void
    {
        $links = PurchaseRequestOrderLink::query()
            ->whereIn('purchase_order_item_id', $order->items()->pluck('id'))
            ->get();

        if ($links->isEmpty()) {
            return;
        }

        [$requests, $items] = $this->lockByItemIds($links->pluck('purchase_request_item_id')->unique()->all());

        foreach ($links as $link) {
            $item = $items->get($link->purchase_request_item_id);
            if (! $item) {
                continue;
            }
            $new = bcsub((string) $item->ordered_base_quantity, (string) $link->base_quantity, 4);

            if (bccomp($new, '0', 4) < 0) {
                $new = '0';
            }

            $item->forceFill(['ordered_base_quantity' => $new])->save();
        }

        PurchaseRequestOrderLink::query()->whereIn('id', $links->pluck('id'))->delete();

        $this->syncStatuses($requests->keys()->all());
    }

    /**
     * Status PR TURUNAN dari qty dipesan.
     *
     * @param list<int> $requestIds
     */
    public function syncStatuses(array $requestIds): void
    {
        $requests = PurchaseRequest::query()->whereIn('id', $requestIds)->with('items')->get();

        foreach ($requests as $request) {
            if (! in_array($request->status, [
                PurchaseRequestStatus::Approved,
                PurchaseRequestStatus::PartiallyOrdered,
                PurchaseRequestStatus::Ordered,
            ], true)) {
                continue;
            }

            $any = $request->items->contains(fn ($i) => bccomp((string) $i->ordered_base_quantity, '0', 4) > 0);
            $all = $request->items->every(fn ($i) => bccomp((string) $i->ordered_base_quantity, (string) $i->base_quantity, 4) >= 0);

            $status = match (true) {
                $all => PurchaseRequestStatus::Ordered,
                $any => PurchaseRequestStatus::PartiallyOrdered,
                default => PurchaseRequestStatus::Approved,
            };

            if ($status !== $request->status) {
                $request->forceFill(['status' => $status])->save();
            }
        }
    }

    /**
     * @param list<int> $itemIds
     * @return array{0: \Illuminate\Support\Collection<int,PurchaseRequest>, 1: \Illuminate\Support\Collection<int,PurchaseRequestItem>}
     */
    private function lockByItemIds(array $itemIds): array
    {
        $found = PurchaseRequestItem::query()->whereIn('id', $itemIds)->get(['id', 'purchase_request_id']);
        if ($found->count() !== count($itemIds)) {
            throw new PurchaseRuleException('Ada item Purchase Request yang tidak ditemukan.');
        }

        $requestIds = $found->pluck('purchase_request_id')->unique()->sort()->values()->all();

        $requests = PurchaseRequest::query()->whereIn('id', $requestIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $items = PurchaseRequestItem::query()->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$requests, $items];
    }
}
