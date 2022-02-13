<?php

/** --------------------------------------------------------------------------------
 * This classes renders the response for the [publish] process for the invoices
 * controller
 * @package    Digital Manager CRM
 * @author     Digital Partnership
 *----------------------------------------------------------------------------------*/

namespace App\Http\Responses\Invoices;
use Illuminate\Contracts\Support\Responsable;

class PublishResponse implements Responsable {

    private $payload;

    public function __construct($payload = array()) {
        $this->payload = $payload;
    }

    /**
     * render the view for invoices
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function toResponse($request) {

        //set all data to arrays
        foreach ($this->payload as $key => $value) {
            $$key = $value;
        }

        //hide publish button
        $jsondata['dom_visibility'][] = [
            'selector' => '#invoice-action-publish-invoice',
            'action' => 'hide',
        ];
        $jsondata['dom_visibility'][] = [
            'selector' => '#invoiceAddPaymentButton',
            'action' => 'show',
        ];
        //update status (due or overdue)
        $jsondata['dom_visibility'][] = [
            'selector' => '#invoice-status-draft',
            'action' => 'hide',
        ];
        if ($invoice->bill_status == 'due') {
            $jsondata['dom_visibility'][] = [
                'selector' => '#invoice-status-due',
                'action' => 'show',
            ];
        } else {
            $jsondata['dom_visibility'][] = [
                'selector' => '#invoice-status-overdue',
                'action' => 'show',
            ];
        }

        //notice
        $jsondata['notification'] = array('type' => 'success', 'value' => __('lang.request_has_been_completed'));

        //response
        return response()->json($jsondata);
    }
}
