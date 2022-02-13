<?php

/** --------------------------------------------------------------------------------
 * This middleware class handles [destroy] precheck processes for clients
 *
 * @package    Digital Manager CRM
 * @author     Digital Partnership
 *----------------------------------------------------------------------------------*/

namespace App\Http\Middleware\Clients;
use Closure;
use Log;

class Destroy {

    public function handle($request, Closure $next) {

        //validate module status
        if (!config('visibility.modules.clients')) {
            abort(404, __('lang.the_requested_service_not_found'));
            return $next($request);
        }

        //for a single item request - merge into an $ids[x] array and set as if checkox is selected (on)
        if (is_numeric($request->route('client'))) {
            $ids[$request->route('client')] = 'on';
            request()->merge([
                'ids' => $ids,
            ]);
        }

        //loop through each estimate and check permissions
        if (is_array(request('ids'))) {

            //validate each item in the list exists
            foreach (request('ids') as $id => $value) {
                //only checked items
                if ($value == 'on') {
                    //validate
                    if (!$client = \App\Models\Client::Where('client_id', $id)->first()) {
                        abort(409, __('lang.one_of_the_selected_items_nolonger_exists'));
                    }
                }
            }

            //permission: does user have permission edit estimates
            if (auth()->user()->is_team) {
                if (auth()->user()->role->role_clients< 3) {
                    abort(403);
                }
            }
            //client - no permissions
            if (auth()->user()->is_client) {
                abort(403);
            }
             //client - no permissions
             if (auth()->user()->is_fournisseur) {
                abort(403);
            }
        } else {
            //no items were passed with this request
            Log::error("no items were sent with this request", ['process' => '[permissions][clients][change-category]', 'ref' => config('app.debug_ref'), 'function' => __function__, 'file' => basename(__FILE__), 'line' => __line__, 'path' => __file__, 'client id' => $client_id ?? '']);
            abort(409);
        }

        //all is on - passed
        return $next($request);
    }
}
