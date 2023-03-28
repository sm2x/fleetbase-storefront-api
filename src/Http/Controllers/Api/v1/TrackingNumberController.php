<?php

namespace Fleetbase\Http\Controllers\Api\v1;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\CreateTrackingNumberRequest;
use Fleetbase\Http\Requests\DecodeTrackingNumberQR;
use Fleetbase\Http\Requests\UpdateTrackingNumberRequest;
use Fleetbase\Http\Resources\v1\DeletedResource;
use Fleetbase\Http\Resources\v1\TrackingNumber as TrackingNumberResource;
use Fleetbase\Models\TrackingNumber;
use Fleetbase\Support\Utils;

class TrackingNumberController extends Controller
{
    /**
     * Creates a new Fleetbase TrackingNumber resource.
     *
     * @param  \Fleetbase\Http\Requests\CreateTrackingNumberRequest  $request
     * @return \Fleetbase\Http\Resources\TrackingNumber
     */
    public function create(CreateTrackingNumberRequest $request)
    {
        // get request input
        $input = $request->only(['region', 'type']);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // owner assignment
        if ($request->has('owner')) {
            $owner = Utils::getUuid(
                ['orders', 'entities'],
                [
                    'public_id' => $request->input('owner'),
                    'company_uuid' => session('company'),
                ]
            );

            if (is_array($owner)) {
                $input['owner_uuid'] = Utils::get($owner, 'uuid');
                $input['type'] = Utils::getModelClassName(Utils::get($owner, 'table'));
            }
        }

        // create the trackingNumber
        $trackingNumber = TrackingNumber::create($input);

        // response the driver resource
        return new TrackingNumberResource($trackingNumber);
    }

    /**
     * Query for Fleetbase TrackingNumber resources.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\TrackingNumberCollection
     */
    public function query(Request $request)
    {
        $results = TrackingNumber::queryFromRequest($request);

        return TrackingNumberResource::collection($results);
    }

    /**
     * Finds a single Fleetbase TrackingNumber resources.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\TrackingNumberCollection
     */
    public function find($id)
    {
        // find for the trackingNumber
        try {
            $trackingNumber = TrackingNumber::findTrackingOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'TrackingNumber resource not found.',
                ],
                404
            );
        }

        // response the trackingNumber resource
        return new TrackingNumberResource($trackingNumber);
    }

    /**
     * Deletes a Fleetbase TrackingNumber resources.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\TrackingNumberCollection
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $trackingNumber = TrackingNumber::findTrackingOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'TrackingNumber resource not found.',
                ],
                404
            );
        }

        // delete the trackingNumber
        $trackingNumber->delete();

        // response the trackingNumber resource
        return new DeletedResource($trackingNumber);
    }

    /**
     * Take the uuid value of an entity QR code and return the object
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function fromQR(DecodeTrackingNumberQR $request)
    {
        // validate request inputs
        $code = $request->input('code');

        // get the model of from the code
        $model = Utils::findModel(['entities', 'orders'], ['uuid' => $code]);

        // if no model response with error
        if (!$model) {
            return response()->json(
                [
                    'error' => 'Unable to find QR code value',
                ],
                400
            );
        }

        // get the model class name
        $modelType = class_basename($model);
        $resourceNamespace = "\\Fleetbase\\Http\\Resources\\v1\\" . $modelType;

        return new $resourceNamespace($model);
    }
}
