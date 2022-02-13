<?php

/** --------------------------------------------------------------------------------
 * This classes renders the response for the [destroy] process for the invoices
 * controller
 * @package    Digital Manager CRM
 * @author     Digital Partnership
 *----------------------------------------------------------------------------------*/

namespace App\Http\Responses\Invoices;

use Illuminate\Contracts\Support\Responsable;

class DestroyResponse implements Responsable
{

    private $payload;

    public function __construct($payload = array())
    {
        $this->payload = $payload;
    }

    /**
     * remove the item from the view
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function toResponse($request)
    {

        //set all data to arrays
        foreach ($this->payload as $key => $value) {
            $$key = $value;
        }

        if (request('invoiceresource_type')=='client'){
            $jsondata['redirect_url'] = url("/clients/" . request('invoiceresource_id') . '/invoices');
            return response()->json($jsondata);

        }

        else{
            if ($count == 0 || $count_ressource == 0) {
                $html = view('notifications/no-results-found',compact('page'))->render();
                $jsondata['dom_html'][] = array(
                    'selector' => '.table-responsive',
                    'action' => 'replace',
                    'value' => $html
                );
                $jsondata['dom_classes'][] = [
                    'selector' => '#invoices-table-wrapper',
                    'action' => 'add',
                    'value' => 'count-0',
                ];
            } else {
                //hide and remove all deleted rows
                foreach ($allrows as $id) {
                    $jsondata['dom_visibility'][] = array(
                        'selector' => '#invoice_' . $id,
                        'action' => 'slideup-slow-remove',
                    );
                }
            }
    
    
            //refresh stats
            if (isset($stats)) {
                $html = view('misc/list-pages-stats-content', compact('stats'))->render();
                $jsondata['dom_html'][] = [
                    'selector' => '#list-pages-stats-widget',
                    'action' => 'replace',
                    'value' => $html,
                ];
            }
    
            //deleting from invoice page
            if (request('source') == 'page') {
                request()->session()->flash('success-notification', __('lang.request_has_been_completed'));
                $jsondata['redirect_url'] = url('invoices');
            }
    
    
            //close modal
            $jsondata['dom_visibility'][] = array('selector' => '#commonModal', 'action' => 'close-modal');
    
            //notice
            $jsondata['notification'] = array('type' => 'success', 'value' => __('lang.request_has_been_completed'));
    
            //response
            return response()->json($jsondata);

        }
    }
}
