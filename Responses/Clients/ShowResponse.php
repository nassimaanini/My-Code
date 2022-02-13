<?php

/** --------------------------------------------------------------------------------
 * This classes renders the response for the [show] process for the clients
 * controller
 * @package    Digital Manager CRM
 * @author     Digital Partnership
 *----------------------------------------------------------------------------------*/

namespace App\Http\Responses\Clients;
use Illuminate\Contracts\Support\Responsable;

class ShowResponse implements Responsable {

    private $payload;

    public function __construct($payload = array()) {
        $this->payload = $payload;
    }

    /**
     * render the view
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function toResponse($request) {

        //set all data to arrays
        foreach ($this->payload as $key => $value) {
            $$key = $value;
        }

        return view('pages/client/wrapper', compact('page', 'client', 'owner', 'fields', 'tags'))->render();

    }

}
