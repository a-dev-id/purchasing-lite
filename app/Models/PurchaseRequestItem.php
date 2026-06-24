<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequestItem extends Model
{
    protected $fillable = [
        'purchase_request_id',
        'sort_order',
        'item_name',
        'specification',
        'quantity',
        'unit',
        'stock',
        'last_purchase_date',
        'item_photo',
        'item_photos',
        'needed_date',
        'estimated_unit_price',
        'estimated_total_price',
        'requester_remarks',
        'purchasing_remarks',
        'cost_control_remarks',
        'gm_remarks',
        'gm_status',
        'gm_decided_by',
        'gm_decided_at',
        'split_from_item_id',
        'split_to_purchase_request_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'stock' => 'decimal:2',
        'last_purchase_date' => 'date',
        'estimated_unit_price' => 'decimal:2',
        'estimated_total_price' => 'decimal:2',
        'item_photos' => 'array',
        'needed_date' => 'date',
        'gm_decided_at' => 'datetime',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function gmDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_decided_by');
    }

    public function splitFromItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'split_from_item_id');
    }

    public function splitToPurchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'split_to_purchase_request_id');
    }

    public function vendorOfferItems(): HasMany
    {
        return $this->hasMany(PurchaseRequestVendorOfferItem::class);
    }
}
