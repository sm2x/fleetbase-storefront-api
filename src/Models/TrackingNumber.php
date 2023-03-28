<?php

namespace Fleetbase\Models;

use Illuminate\Support\Carbon;
use Fleetbase\Casts\Point;
use Fleetbase\Support\Utils;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\TracksApiCredential;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\SendsWebhooks;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Milon\Barcode\Facades\DNS2DFacade as DNS2D;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrackingNumber extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SendsWebhooks, TracksApiCredential, SpatialTrait;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'tracking_numbers';

    /**
     * The type of public Id to generate
     *
     * @var string
     */
    protected $publicIdType = 'track';

    /**
     * These attributes that can be queried
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['_key', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'region', 'qr_code', 'barcode', 'status_uuid'];

    /**
     * The attributes that are spatial columns.
     *
     * @var array
     */
    protected $spatialFields = ['location'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'location' => Point::class,
    ];

    /**
     * Dynamic attributes that are appended to object
     *
     * @var array
     */
    protected $appends = ['last_status', 'last_status_code', 'last_status_updated_at', 'type'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['status'];

    /**
     * Tracking number status
     */
    public function getLastStatusAttribute()
    {
        return $this->status->status;
    }

    /**
     * Tracking number status code
     */
    public function getLastStatusCodeAttribute()
    {
        return $this->status->code;
    }

    /**
     * Datetime of last status update
     */
    public function getLastStatusUpdatedAtAttribute()
    {
        return $this->status->created_at;
    }

    /**
     * Get the service details
     *
     * @var Model
     */
    public function status()
    {
        return $this->hasOne(TrackingStatus::class)->latest()->without(['trackingNumber']);
    }

    /**
     * Tracking statuses by this tracking number
     *
     * @var Model
     */
    public function statuses()
    {
        return $this->hasMany(TrackingStatus::class)->without(['trackingNumber']);
    }

    /**
     * Get the order status belongss to
     *
     * @var Model
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'owner_uuid');
    }

    /**
     * Get the entity tracking number belongss to
     *
     * @var Model
     */
    public function entity()
    {
        return $this->belongsTo(Entity::class, 'owner_uuid');
    }

    /**
     * Get the tracking number owner, could be order or entity
     *
     * @var Model
     */
    public function owner()
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid')->withoutGlobalScopes();
    }

    /**
     * Get the tracking number type
     *
     * @var Model
     */
    public function getTypeAttribute()
    {
        return Utils::getTypeFromClassName($this->owner_type);
    }

    /**
     * Generates a fleetbase tracking number
     *
     * @var array
     */
    public static function generateTrackingNumber($region = 'SG', $length = 10)
    {
        $company = Company::where('uuid', session('company'))->withoutGlobalScopes()->first();
        $companyName = $company ? strtoupper(substr($company->name, 0, 3)) : null;
        $number = $companyName ?? 'FLB';

        for ($i = 0; $i < $length; $i++) {
            $number .= mt_rand(0, 9);
        }
        return $number . strtoupper($region);
    }

    /**
     * Generates a unique fleetbase tracking number
     *
     * @var array
     */
    public static function generateNumber($region = 'SG', $length = 10)
    {
        $n = static::generateTrackingNumber($region, $length);
        $tr = static::where('tracking_number', $n)
            ->withTrashed()
            ->first();
        while (is_object($tr) && $n == $tr->tracking_number) {
            $n = static::generateTrackingNumber($region, $length);
        }
        return $n;
    }

    /**
     * Find a model by its public_id key or throw an exception.
     *
     * @param  mixed  $id
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|static|static[]
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public static function findTrackingOrFail($id)
    {
        $result = static::query()
            ->select(['*'])
            ->with(['status'])
            ->where(function ($q) use ($id) {
                $q->where('public_id', $id);
                $q->orWhere('tracking_number', $id);
                $q->orWhere('uuid', $id);
            })
            ->first();

        if (!is_null($result)) {
            return $result;
        }

        throw (new ModelNotFoundException())->setModel(static::class, $id);
    }

    public function updateOwnerStatus(?TrackingStatus $trackingStatus = null)
    {
        $trackingStatus = $trackingStatus ?? $this->load(['status'])->getRelationValue('status');
        // update status on owner
        $status = strtolower($trackingStatus->code);
        $owner = $this->load(['owner'])->getRelationValue('owner');

        if ($owner && $owner->isFillable('status') && $owner->status !== $status) {
            $this->owner->status = $status;

            $this->owner->save();
        }

        return $this;
    }

    public static function insertGetUuid($values = [], ?Model $owner = null)
    {
        $instance = new static();
        $fillable = $instance->getFillable();
        $insertKeys = array_keys($values);
        // clean insert data
        foreach ($insertKeys as $key) {
            if (!in_array($key, $fillable)) {
                unset($values[$key]);
            }
        }

        $values['uuid'] = $uuid = static::generateUuid();
        $values['public_id'] = static::generatePublicId('track');
        $values['_key'] = session('api_key') ?? 'console';
        $values['created_at'] = Carbon::now()->toDateTimeString();
        $values['company_uuid'] = session('company');

        if ($owner) {
            $values['owner_uuid'] = $owner->uuid;
            $values['owner_type'] = Utils::getMutationType($owner);
        }

        $values['tracking_number'] = TrackingNumber::generateNumber($values['region'] ?? 'SG');
        $values['qr_code'] = DNS2D::getBarcodePNG($values['owner_uuid'], 'QRCODE');
        $values['barcode'] = DNS2D::getBarcodePNG($values['owner_uuid'], 'PDF417');

        if (isset($values['meta']) && (is_object($values['meta']) || is_array($values['meta']))) {
            $values['meta'] = json_encode($values['meta']);
        }

        $result = static::insert($values);

        if (!$result) {
            return false;
        }

        $ownerTypeName = class_basename($values['owner_type']);

        // create initial status
        $trackingStatusId = TrackingStatus::insertGetUuid([
            'tracking_number_uuid' => $uuid,
            'status' => Str::title($ownerTypeName . ' created'),
            'details' => 'New ' . Str::lower($ownerTypeName) . ' created.',
            'location' => $values['location'] ?? Utils::parsePointToWkt(new Point(0, 0)),
            'code' => 'CREATED'
        ]);

        // update status of tracking number
        TrackingNumber::where('uuid', $uuid)->update(['status_uuid' => $trackingStatusId]);

        // update owner status
        if ($owner && $owner instanceof Model && $owner->isFillable('status')) {
            // runs event cycles
            // $model->update([ 'status' => 'created' ]);

            // silent update
            DB::table($owner->getTable())->where('uuid', $owner->uuid)->update(['status' => 'created']);
        }

        return $uuid;
    }
}
