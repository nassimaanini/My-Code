<?php

/** --------------------------------------------------------------------------------
 * This controller manages all the business logic for invoices
 *
 * @package    Digital Manager CRM
 * @author     Digital Partnership
 *----------------------------------------------------------------------------------*/

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;

use App\Http\Requests\Invoices\InvoiceClone;
use App\Http\Requests\Invoices\InvoiceRecurrringSettings;
use App\Http\Requests\Invoices\InvoiceSave;
use App\Http\Requests\Invoices\InvoiceStoreUpdate;

use App\Http\Responses\Common\ChangeCategoryResponse;
use App\Http\Responses\Invoices\AttachProjectResponse;
use App\Http\Responses\Invoices\ChangeCategoryUpdateResponse;
use App\Http\Responses\Invoices\CreateCloneResponse;
use App\Http\Responses\Invoices\CreateResponse;
use App\Http\Responses\Invoices\DestroyResponse;
use App\Http\Responses\Invoices\EditResponse;
use App\Http\Responses\Invoices\IndexResponse;
use App\Http\Responses\Invoices\PDFResponse;
use App\Http\Responses\Invoices\PublishResponse;
use App\Http\Responses\Invoices\RecurringSettingsResponse;
use App\Http\Responses\Invoices\ResendResponse;
use App\Http\Responses\Invoices\SaveResponse;
use App\Http\Responses\Invoices\ShowResponse;
use App\Http\Responses\Invoices\StoreCloneResponse;
use App\Http\Responses\Invoices\StoreResponse;
use App\Http\Responses\Invoices\UpdateResponse;
use App\Http\Responses\Pay\MolliePaymentResponse;
use App\Http\Responses\Pay\PaypalPaymentResponse;
use App\Http\Responses\Pay\RazorpayPaymentResponse;
use App\Http\Responses\Pay\StripePaymentResponse;
use App\Http\Responses\Avoirs\ConvertToAvoirResponse;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Lineitem;
use App\Models\Stock;
use App\Models\Project;
use App\Repositories\CategoryRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CloneInvoiceRepository;
use App\Repositories\DestroyRepository;
use App\Repositories\EmailerRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventTrackingRepository;
use App\Repositories\InvoiceGeneratorRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\LineitemRepository;
use App\Repositories\MolliePaymentRepository;
use App\Repositories\PaypalPaymentRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\RazorpayPaymentRepository;
use App\Repositories\StripePaymentRepository;
use App\Repositories\TagRepository;
use App\Repositories\TaxRepository;
use App\Repositories\UserRepository;
use App\Repositories\ItemRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use PDF;
use Validator;
use ZipArchive;

class Invoices extends Controller
{

    /**
     * The invoice repository instance.
     */
    protected $invoicerepo;

    /**
     * The tags repository instance.
     */
    protected $tagrepo;

    /**
     * The user repository instance.
     */
    protected $userrepo;

    /**
     * The tax repository instance.
     */
    protected $taxrepo;

    /**
     * The unit repository instance.
     */
    protected $unitrepo;

    /**
     * The line item repository instance.
     */
    protected $lineitemrepo;

    /**
     * The event tracking repository instance.
     */
    protected $trackingrepo;

    /**
     * The event repository instance.
     */
    protected $eventrepo;

    /**
     * The emailer repository
     */
    protected $emailerrepo;

    /**
     * The invoice generator repository
     */
    protected $invoicegenerator;
    protected $itemRepo;

    public function __construct(
        InvoiceRepository $invoicerepo,
        TagRepository $tagrepo,
        UserRepository $userrepo,
        TaxRepository $taxrepo,
        LineitemRepository $lineitemrepo,
        EventRepository $eventrepo,
        EventTrackingRepository $trackingrepo,
        EmailerRepository $emailerrepo,
        InvoiceGeneratorRepository $invoicegenerator,
        ItemRepository $itemRepo
    ) {

        //core controller instantation
        parent::__construct();

        //authenticated
        $this->middleware('auth');

        $this->middleware('invoicesMiddlewareIndex')->only([
            'index',
            'update',
            'store',
            'changeCategoryUpdate',
            'attachProjectUpdate',
            'dettachProject',
            'stopRecurring',
            'recurringSettingsUpdate',
        ]);

        $this->middleware('invoicesMiddlewareCreate')->only([
            'create',
            'store',
        ]);

        $this->middleware('invoicesMiddlewareEdit')->only([
            'edit',
            'update',
            'createClone',
            'storeClone',
            'stopRecurring',
            'dettachProject',
            'attachProject',
            'attachProjectUpdate',
            'emailClient',
            'saveInvoice',
            'recurringSettings',
            'recurringSettingsUpdate',
        ]);

        $this->middleware('invoicesMiddlewareShow')->only([
            'show',
            'paymentStripe',
            'downloadPDF',
        ]);

        $this->middleware('invoicesMiddlewareDestroy')->only([
            'destroy',
        ]);

        //only needed for the [action] methods
        $this->middleware('invoicesMiddlewareBulkEdit')->only([
            'changeCategoryUpdate',
        ]);

        $this->invoicerepo = $invoicerepo;
        $this->tagrepo = $tagrepo;
        $this->userrepo = $userrepo;
        $this->lineitemrepo = $lineitemrepo;
        $this->taxrepo = $taxrepo;
        $this->eventrepo = $eventrepo;
        $this->trackingrepo = $trackingrepo;
        $this->emailerrepo = $emailerrepo;
        $this->invoicegenerator = $invoicegenerator;
        $this->itemRepo = $itemRepo;

    }

    /**
     * Display a listing of invoices
     * @param object ProjectRepository instance of the repository
     * @param object CategoryRepository instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function index(ProjectRepository $projectrepo, CategoryRepository $categoryrepo)
    {

        //default
        $projects = [];
        request()->merge([
            'filter_show_archived_projects' => 'yes',
        ]);

        //get invoices
        $invoices = $this->invoicerepo->search();

        //get all categories (type: invoice) - for filter panel
        $categories = $categoryrepo->get('invoice');

        //get all tags (type: lead) - for filter panel
        $tags = $this->tagrepo->getByType('invoice');

        //refresh invoices
        foreach ($invoices as $invoice) {
            $this->invoicerepo->refreshInvoice($invoice);
        }

        //get clients project list
        if (config('visibility.filter_panel_clients_projects')) {
            if (is_numeric(request('invoiceresource_id'))) {
                $projects = $projectrepo->search('', ['project_clientid' => request('invoiceresource_id')]);
            }
        }

        //reponse payload
        $payload = [
            'page' => $this->pageSettings('invoices'),
            'invoices' => $invoices,
            'projects' => $projects,
            'stats' => $this->statsWidget(),
            'categories' => $categories,
            'tags' => $tags,
        ];

        //show the view
        return new IndexResponse($payload);
    }

    /**
     * Show the form for creating a new invoice
     * @param object CategoryRepository instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function createSelector()
    {

        $payload = [
            'title' => __('lang.add_invoice_splah_title'),

        ];

        //show the form
        return new CreateSelectorResponse($payload);
    }

    /**
     * Show the form for creating a new invoice
     * @param object CategoryRepository instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function create(CategoryRepository $categoryrepo)
    {

        //invoice categories
        $categories = $categoryrepo->get('invoice');

        //get tags
        $tags = $this->tagrepo->getByType('invoice');

        //reponse payload
        $payload = [
            'page' => $this->pageSettings('create'),
            'categories' => $categories,
            'tags' => $tags,
        ];

        //show the form
        return new CreateResponse($payload);
    }

    /**
     * Store a newly created invoicein storage.
     * @param object InvoiceStoreUpdate instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function store(InvoiceStoreUpdate $request, ClientRepository $clientrepo)
    {

        //are we creating a new client
        if (request('client-selection-type') == 'new') {

            //create client
            if (!$client_id = $clientrepo->create([
                'send_email' => 'yes',
                'return' => 'id',
            ])) {
                abort(409);
            }

            //add client id to request
            request()->merge([
                'bill_clientid' => $client_id,
            ]);
        }

        //create the invoice
        if (!$bill_invoiceid = $this->invoicerepo->create()) {
            abort(409);
        }

        //add tags
        $this->tagrepo->add('invoice', $bill_invoiceid);

        //payloads - expense
        $this->expensesPayload($bill_invoiceid);

        //reponse payload
        $payload = [
            'id' => $bill_invoiceid,
        ];

        //process reponse
        return new StoreResponse($payload);
    }

    /**
     * Display the specified invoice
     *  [web preview example]
     *  http://example.com/invoices/29/pdf?view=preview
     * @param  int  $id invoice id
     * @return \Illuminate\Http\Response
     */
    public function show($id) {
        //get invoice object payload
        if (!$payload = $this->invoicegenerator->generate($id)) {
            abort(409, __('lang.error_request_could_not_be_completed'));
        }

        //append to payload
        $payload['page'] = $this->pageSettings('invoice', $payload['bill']);

        //mark events as read
        \App\Models\EventTracking::where('parent_id', $id)
            ->where('parent_type', 'invoice')
            ->where('eventtracking_userid', auth()->id())
            ->update(['eventtracking_status' => 'read']);

        //if client - marked as opened
        if (auth()->user()->is_client) {
            \App\Models\Invoice::where('bill_invoiceid', $id)
                ->update(['bill_viewed_by_client' => 'yes']);
        }

        //custom fields
        $payload['customfields'] = \App\Models\CustomField::Where('customfields_type', 'clients')->get();

        //download pdf invoice
        if (request()->segment(3) == 'pdf') {
            //render view

            return new PDFResponse($payload);
        }
        //process reponse
        return new ShowResponse($payload);
    }

    /**
     * save invoice changes, when an ioice is being edited
     * @param object InvoiceSave instance of the request validation
     * @param int $id invoice id
     * @return array
     */
    public function saveInvoice(InvoiceSave $request, $id)
    {
        //get the invoice
        $invoices = $this->invoicerepo->search($id);
        $invoice = $invoices->first(); 
        
        // verification Stock != draft    
        if($invoice->bill_status != "draft"){
            $message = $this->itemRepo->verificationStock($id,"invoice" , "En stock","Réservés");
            if($message) abort(409, __($message));
        }      

        //save each line item in the database
        $this->invoicerepo->saveLineItems($id, $invoice->bill_projectid);

        //update taxes
        $this->updateInvoiceTax($id);

        //update other invoice attributes
        $this->invoicerepo->updateInvoice($id);

        //send mail
        if($invoice->bill_status != "draft"){
            /** ----------------------------------------------
             * record event [comment]
             * ----------------------------------------------*/
            $resource_id = (is_numeric($invoice->bill_projectid)) ? $invoice->bill_projectid : $invoice->bill_clientid;
            $resource_type = (is_numeric($invoice->bill_projectid)) ? 'project' : 'client';
            $data = [
                'event_creatorid' => auth()->id(),
                'event_item' => 'invoice',
                'event_item_id' => $invoice->bill_invoiceid,
                'event_item_lang' => 'event_created_invoice',
                'event_item_content' => __('lang.invoice') . ' - ' . $invoice->formatted_bill_invoiceid,
                'event_item_content2' => '',
                'event_parent_type' => 'invoice',
                'event_parent_id' => $invoice->bill_invoiceid,
                'event_parent_title' => $invoice->project_title,
                'event_clientid' => $invoice->bill_clientid,
                'event_show_item' => 'yes',
                'event_show_in_timeline' => 'yes',
                'eventresource_type' => $resource_type,
                'eventresource_id' => $resource_id,
                'event_notification_category' => 'notifications_billing_activity',

            ];
            //record event
            if ($event_id = $this->eventrepo->create($data)) {
                //get users (main client)
                $users = $this->userrepo->getClientUsers($invoice->bill_clientid, 'owner', 'ids');
                //record notification
                $emailusers = $this->trackingrepo->recordEvent($data, $users, $event_id);
            }
            /** ----------------------------------------------
             * send email [queued]
             * ----------------------------------------------*/
            if (isset($emailusers) && is_array($emailusers)) {
                //other data
                $data = [];
                //send to client users
                if ($users = \App\Models\User::WhereIn('id', $emailusers)->get()) {
                    foreach ($users as $user) {
                        $mail = new \App\Mail\PublishInvoice($user, $data, $invoice);
                        $mail->build();
                    }
                }
            }                                                           
        }
        //reponse payload
        $payload = [
            'invoice' => $invoice,
        ];
        //response
        return new SaveResponse($payload);
    }

    /**
     * update the tax for an invoice
     * (1) delete existing invoice taxes
     * (2) for summary taxes - save new taxes
     * (3) [future]  - calculate and save line taxes (probably should just come from the frontend, same as summary taxes)
     * @param int $bill_invoiceid invoice id
     * @return array
     */
    private function updateInvoiceTax($bill_invoiceid = '')
    {

        //delete current invoice taxes
        \App\Models\Tax::Where('taxresource_type', 'invoice')
            ->where('taxresource_id', $bill_invoiceid)
            ->delete();

        //save taxes [summary taxes]
        if (is_array(request('bill_logic_taxes'))) {
            foreach (request('bill_logic_taxes') as $tax) {
                //get data elements
                $list = explode('|', $tax);
                $data = [
                    'tax_taxrateid' => $list[2],
                    'tax_name' => $list[1],
                    'tax_rate' => $list[0],
                    'taxresource_type' => 'invoice',
                    'taxresource_id' => $bill_invoiceid,
                ];
                $this->taxrepo->create($data);
            }
        }
    }

    /**
     * publish an invoice
     * @param int $id invoice id
     * @return \Illuminate\Http\Response
     */
    public function publishInvoice($id) {
        $message = "";
        $line_items=Lineitem::Where("lineitemresource_id",$id)->get();
        Log::alert('$line_items : ' . $line_items);
        foreach ($line_items as $key => $line_item) {
            if($line_item->lineitem_item_id){
                Log::alert( 'inside line item product condition');
                $item_stock = Item::where("item_id",$line_item->lineitem_item_id)->with("stock")->first();
                $qte_current_stock = Stock::Where("stock_id",$item_stock->stock->stock_id)->value("quantite");
                if($qte_current_stock<$line_item->lineitem_quantity){
                    $message .= "<i>". $item_stock->item_name." est en rupture de stock.</i><br/>";
                }
            }
        }
        if($message) abort(409, __($message));
        //generate the invoice
        if (!$payload = $this->invoicegenerator->generate($id)) {
            abort(409, __('lang.error_loading_item'));
        }
        //invoice
        $invoice = $payload['bill'];
        
        if($invoice->bill_subtotal == 0){
            abort(409, __('lang.invoice_still_blank'));
        }        
        $ice_client = \App\Models\Client::where('client_id', $invoice->bill_clientid)->value("company_ice");
        if (!$ice_client) {
            abort(409, __('lang.ice_client_must_not_be_blank'));
        }
        $ice_company = \App\Models\Settings::value("settings_company_ice");
        if (!$ice_company) {
            abort(409, __('lang.ice_company_must_not_be_blank'));
        }
        //validate current status
        if ($invoice->bill_status != 'draft') {
            abort(409, __('lang.invoice_already_piblished'));
        }

        /** ----------------------------------------------
         * record event [comment]
         * ----------------------------------------------*/
        $resource_id = (is_numeric($invoice->bill_projectid)) ? $invoice->bill_projectid : $invoice->bill_clientid;
        $resource_type = (is_numeric($invoice->bill_projectid)) ? 'project' : 'client';
        $data = [
            'event_creatorid' => auth()->id(),
            'event_item' => 'invoice',
            'event_item_id' => $invoice->bill_invoiceid,
            'event_item_lang' => 'event_created_invoice',
            'event_item_content' => __('lang.invoice') . ' - ' . $invoice->formatted_bill_invoiceid,
            'event_item_content2' => '',
            'event_parent_type' => 'invoice',
            'event_parent_id' => $invoice->bill_invoiceid,
            'event_parent_title' => $invoice->project_title,
            'event_clientid' => $invoice->bill_clientid,
            'event_show_item' => 'yes',
            'event_show_in_timeline' => 'yes',
            'eventresource_type' => $resource_type,
            'eventresource_id' => $resource_id,
            'event_notification_category' => 'notifications_billing_activity',

        ];
        //record event
        if ($event_id = $this->eventrepo->create($data)) {
            //get users (main client)
            $users = $this->userrepo->getClientUsers($invoice->bill_clientid, 'owner', 'ids');
            //record notification
            $emailusers = $this->trackingrepo->recordEvent($data, $users, $event_id);
        }
        /** ----------------------------------------------
         * send email [queued]
         * ----------------------------------------------*/
        if (isset($emailusers) && is_array($emailusers)) {
            //other data
            $data = [];
            //send to client users
            if ($users = \App\Models\User::WhereIn('id', $emailusers)->get()) {
                foreach ($users as $user) {
                    $mail = new \App\Mail\PublishInvoice($user, $data, $invoice);
                    $mail->build();
                }
            }
        }

        //get invoice again
        $invoice = \App\Models\Invoice::Where('bill_invoiceid', $invoice->bill_invoiceid)->first();

        //get new invoice status and save it
        $bill_date = \Carbon\Carbon::parse($invoice->bill_date);
        $bill_due_date = \Carbon\Carbon::parse($invoice->bill_due_date);
        if ($bill_due_date->diffInDays(today(), false) < 0 || !$invoice->bill_due_date ) {
            $invoice->bill_status = 'due';
        } else {
            $invoice->bill_status = 'overdue';
        }

       //  dd();
        if ($invoice->save()) {
            $line_items = Lineitem::where("lineitemresource_id", $invoice->bill_invoiceid)->get();
            foreach ($line_items as $key => $line_item) {
                if($line_item->lineitem_item_id){
                    $item_reserved = Item::where("item_id", $line_item->lineitem_item_id)->with("reserver")->first();
                    $item_stock = Item::where("item_id", $line_item->lineitem_item_id)->with("stock")->first();
                    Stock::Where("stock_id", $item_reserved->reserver->stock_id)->update(array('quantite' => DB::raw('quantite+' . $line_item->lineitem_quantity)));
                    Stock::Where("stock_id", $item_stock->stock->stock_id)->update(array('quantite' => DB::raw('quantite-' . $line_item->lineitem_quantity)));
    
                    if ($item_stock->stock->quantite > $item_stock->item_stock_limit) {
                        $item_stock = Item::where("item_id", $line_item->lineitem_item_id)->with("stock")->first();
    
                        if ($item_stock->stock->quantite <= $item_stock->item_stock_limit) {
                            $data = [
                                'event_creatorid' => auth()->id(),
                                'event_item' => 'item',
                                'event_item_id' => $item_stock->item_id,
                                'event_item_lang' => 'event_stock_limite',
                                'event_item_content' => __('lang.item') . ' - ' . $item_stock->item_name,
                                'event_item_content2' => '',
                                'event_parent_type' => 'item',
                                'event_parent_id' => $item_stock->item_id,
                                'event_parent_title' => '',
                                'event_clientid' => '',
                                'event_show_item' => 'yes',
                                'event_show_in_timeline' => 'no',
                                'eventresource_type' => 'item',
                                'eventresource_id' => $item_stock->item_id,
                                'event_notification_category' => 'notifications_stock_limit',
    
                            ];
    
                            //record event
                            if ($event_id = $this->eventrepo->create($data)) {
                                //get team users
                                $users = $this->userrepo->getTeamMembers();
                                //record notification
                                $emailusers = $this->trackingrepo->recordEvent($data, $users, $event_id);
                            }
                            /** ----------------------------------------------
                             * send email [queued]
                             * ----------------------------------------------*/
                            if (isset($emailusers) && is_array($emailusers)) {
                                //other data
                                $data = [];
                                //send to client users
                                if ($users = \App\Models\User::WhereIn('id', $emailusers)->get()) {
                                    foreach ($users as $user) {
                                        $mail = new \App\Mail\PublishStockAlert($user, $data, $item_stock);
                                        $mail->build();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        //reponse payload
        $payload = [
            'invoice' => $invoice,
        ];
        //response
        return new PublishResponse($payload);
    }

    /**
     * email (resend) an invoice
     * @param int $id invoice id
     * @return \Illuminate\Http\Response
     */
    public function resendInvoice($id)
    {

        //generate the invoice
        if (!$payload = $this->invoicegenerator->generate($id)) {
            abort(409, __('lang.error_loading_item'));
        }

        //invoice
        $invoice = $payload['bill'];

        //validate current status
        if ($invoice->bill_status == 'draft') {
            abort(409, __('lang.invoice_still_draft'));
        }

        /** ----------------------------------------------
         * send email [queued]
         * ----------------------------------------------*/
        $users = $this->userrepo->getClientUsers($invoice->bill_clientid, 'owner', 'collection');
        //other data
        $data = [];
        foreach ($users as $user) {
            $mail = new \App\Mail\PublishInvoice($user, $data, $invoice);
            $mail->build();
        }

        //response
        return new ResendResponse();
    }

    /**
     * Show the form for editing the specified invoice
     * @param object CategoryRepository instance of the repository
     * @param  int  $id invoice id
     * @return \Illuminate\Http\Response
     */
    public function edit(CategoryRepository $categoryrepo, $id)
    {



        //get the project
        $invoice = $this->invoicerepo->search($id);

        //client categories
        $categories = $categoryrepo->get('invoice');

        //get invoicetags and users tags
        $tags_resource = $this->tagrepo->getByResource('invoice', $id);
        // $tags_user = $this->tagrepo->getByType('invoice');
        // $tags = $tags_resource->merge($tags_user);
        $tags = $tags_resource->unique('tag_title');

        //not found
        if (!$invoice = $invoice->first()) {
            abort(409, __('lang.error_loading_item'));
        }
        $projects = Project::Where("project_clientid",$invoice->bill_clientid)->get();
        //reponse payload
        $payload = [
            'page' => $this->pageSettings('edit'),
            'invoice' => $invoice,
            'categories' => $categories,
            'tags' => $tags,
            'projects' => $projects,
        ];

        //response
        return new EditResponse($payload);
    }

    /**
     * Update the specified invoicein storage.
     * @param  int  $id invoice id
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        //custom error messages
        $messages = [];

        //validate
        $validator = Validator::make(request()->all(), [
            'bill_date' => 'required|date',
            'bill_due_date' => [
                // 'required',
                // 'date',
                function ($attribute, $value, $fail) {
                    if ($value != '' && request('bill_date') != '' && (strtotime($value) < strtotime(request('bill_date')))) {
                        return $fail(__('lang.due_date_must_be_after_start_date'));
                    }
                }
            ],
            'bill_categoryid' => [
                'required',
                Rule::exists('categories', 'category_id'),
            ],
            'bill_clientid' => [
                'required',
                Rule::exists('clients', 'client_id'),
            ],
        ], $messages);

        //errors
        if ($validator->fails()) {
            $errors = $validator->errors();
            $messages = '';
            foreach ($errors->all() as $message) {
                $messages .= "<li>$message</li>";
            }

            abort(409, $messages);
        }

        //update
        if (!$this->invoicerepo->update($id)) {
            abort(409);
        }

        //delete & update tags
        $this->tagrepo->delete('invoice', $id);
        $this->tagrepo->add('invoice', $id);

        //get project
        $invoices = $this->invoicerepo->search($id);
        $invoice = $invoices->first();

        //refresh invoice
        $this->invoicerepo->refreshInvoice($invoice);

        //reponse payload
        $payload = [
            'invoices' => $invoices,
            'source' => request('source'),
        ];

        //generate a response
        return new UpdateResponse($payload);
    }

    /**edit
     * Remove the specified invoicefrom storage.
     * @param object DestroyRepository instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function destroy(DestroyRepository $destroyrepo)
    {
        //delete each record in the array
        $allrows = array();
        foreach (request('ids') as $id => $value) {
            //only checked items
            if ($value == 'on') {
                //delete file
                $destroyrepo->destroyInvoice($id);
                //add to array
                $allrows[] = $id;
            }
        }
        $rows = $this->invoicerepo->search();
        $count = $rows->count();
        
        if(request("invoiceresource_id") &&  in_array(request("invoiceresource_type"),['client', 'project']))
            $count_ressource = Invoice::where("bill_" . request("invoiceresource_type") ."id",request("invoiceresource_id"))->where('bill_type','invoice')->count();

        //reponse payload
        $payload = [
            'allrows' => $allrows,
            'stats' => $this->statsWidget(),
            'count' => $count,
            'count_ressource' => isset($count_ressource) ? $count_ressource : -1,
            'page' => [
                'no_results_message' => !empty(request('filter_bill_status')) ? (in_array('due', request('filter_bill_status')) ? __('lang.no_results_found_invoice_due') : __('lang.no_results_found_invoice_overdue')) : __('lang.no_results_found_invoice'),
                'no_results_sub_message' => __('lang.no_results_sub_invoice'),
            ]
        ];
        //generate a response
        return new DestroyResponse($payload);
    }

    /**
     * Show the form for updating the invoice
     * @param object CategoryRepository instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function changeCategory(CategoryRepository $categoryrepo)
    {

        //get all invoice categories
        $categories = $categoryrepo->get('invoice');

        //reponse payload
        $payload = [
            'categories' => $categories,
        ];

        //show the form
        return new ChangeCategoryResponse($payload);
    }

    /**
     * Show the form for updating the invoice
     * @param object CategoryRepository instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function changeCategoryUpdate(CategoryRepository $categoryrepo)
    {

        //validate the category exists
        if (!\App\Models\Category::Where('category_id', request('category'))
            ->Where('category_type', 'invoice')
            ->first()) {
            abort(409, __('lang.category_not_found'));
        }

        //update each invoice
        $allrows = array();
        foreach (request('ids') as $bill_invoiceid => $value) {
            if ($value == 'on') {
                $invoice = \App\Models\Invoice::Where('bill_invoiceid', $bill_invoiceid)->first();
                //update the category
                $invoice->bill_categoryid = request('category');
                $invoice->save();
                //get the invoice in rendering friendly format
                $invoices = $this->invoicerepo->search($bill_invoiceid);
                //add to array
                $allrows[] = $invoices;
            }
        }

        //reponse payload
        $payload = [
            'allrows' => $allrows,
        ];

        //show the form
        return new ChangeCategoryUpdateResponse($payload);
    }

    /**
     * Show the form for attaching a project to an invoice
     * @return \Illuminate\Http\Response
     */
    public function attachProject()
    {

        //get client id
        $client_id = request('client_id');

        //reponse payload
        $payload = [
            'projects_feed_url' => url("/feed/projects?ref=clients_projects&client_id=$client_id"),
        ];

        //show the form
        return new AttachProjectResponse($payload);
    }

    /**
     * attach a project to an invoice
     * @return \Illuminate\Http\Response
     */
    public function attachProjectUpdate()
    {

        //validate the invoice exists
        $invoice = \App\Models\Invoice::Where('bill_invoiceid', request()->route('invoice'))->first();

        //validate the project exists
        if (!$project = \App\Models\Project::Where('project_id', request('attach_project_id'))->first()) {
            abort(409, __('lang.project_not_found'));
        }

        //update the invoice
        $invoice->bill_projectid = request('attach_project_id');
        $invoice->bill_clientid = $project->project_clientid;
        $invoice->save();

        //get refreshed invoice
        $invoices = $this->invoicerepo->search(request()->route('invoice'));
        $invoice = $invoices->first();

        //get all payments and add project
        if ($payments = \App\Models\Payment::Where('payment_invoiceid', request()->route('invoice'))->get()) {
            foreach ($payments as $payment) {
                $payment->payment_projectid = request('attach_project_id');
                $payment->save();
            }
        }

        //refresh invoice
        $this->invoicerepo->refreshInvoice($invoice);

        //reponse payload
        $payload = [
            'invoices' => $invoices,
        ];

        //show the form
        return new UpdateResponse($payload);
    }

    /**
     * dettach invoice from a project
     * @return \Illuminate\Http\Response
     */
    public function dettachProject()
    {

        //validate the invoice exists
        $invoice = \App\Models\Invoice::Where('bill_invoiceid', request()->route('invoice'))->first();

        //update the invoice
        $invoice->bill_projectid = null;
        $invoice->save();

        //get refreshed invoice
        $invoices = $this->invoicerepo->search(request()->route('invoice'));

        //get all payments and remove project
        if ($payments = \App\Models\Payment::Where('payment_invoiceid', request()->route('invoice'))->get()) {
            foreach ($payments as $payment) {
                $payment->payment_projectid = null;
                $payment->save();
            }
        }

        //reponse payload
        $payload = [
            'invoices' => $invoices,
        ];

        //show the form
        return new UpdateResponse($payload);
    }



    public function pdfs()
    {
        //delete each record in the array
        $allrows = array();
        foreach (request('ids') as $id => $value) {
            //only checked items
            if ($value == 'on') {

                if (!$payload = $this->invoicegenerator->generate($id)) {
                    abort(409, __('lang.error_request_could_not_be_completed'));
                }

                $payload['page'] = $this->pageSettings('invoice', $payload['bill']);
                $payload['customfields'] = \App\Models\CustomField::Where('customfields_type', 'clients')->get();

                foreach ($payload as $key => $value) {
                    $$key = $value;
                }

                config(['css.bill_mode' => 'pdf-mode-download']);
                $pdf = PDF::loadView('pages/bill/bill-pdf', compact('page', 'bill', 'taxrates', 'taxes', 'lineitems', 'elements', 'customfields'));
                $filename = strtoupper(__('lang.invoice')) . '-' . $bill->formatted_bill_invoiceid . '.pdf'; //estate_inv0001.pdf

                Storage::put('pdf/' . $filename, $pdf->output());

                //add to array
                $allrows[] = $filename;
            }
        }

        $zip = new ZipArchive;
        $fileName = 'facture-' . date('d-M-Y') . '.zip';
        $zipname = BASE_DIR . '/storage/archives/' . $fileName;
        if (file_exists($zipname)) unlink($zipname);
        if ($zip->open($zipname, ZipArchive::CREATE) === TRUE) {
            foreach ($allrows as $file) {
                $file_path = BASE_DIR . "/storage/pdf/$file";
                $zip->addFile($file_path, basename($file_path));
            }

            if ($zip->close() === false) {
                Log::alert("Error creating ZIP file");
            };

            if (file_exists($zipname)) {

                $payload = ['download_zip_url' =>  url('/storage/archives/' . $fileName), 'file_name' => $fileName];

                return $payload;

                header("Pragma: public");
                header("Expires: 0");
                header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
                header("Cache-Control: public");
                header("Content-Description: File Transfer");
                header("Content-type: application/zip");
                header("Content-Disposition: attachment; filename=$fileName");
                header("Content-Transfer-Encoding: binary");
                header("Content-Length: " . filesize($zipname));

                while (ob_get_level()) {
                    ob_end_clean();
                }


                @readfile(url('/storage/archives/' . $fileName));

                exit;
            }
        }
    }







    /**
     * show the form for cloning an invoice
     * @param object CategoryRepository instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function createClone(CategoryRepository $categoryrepo, $id)
    {

        //get the invoice
        $invoice = \App\Models\Invoice::Where('bill_invoiceid', $id)->first();

        //get tags
        $tags = $this->tagrepo->getByType('invoice');

        //invoice categories
        $categories = $categoryrepo->get('invoice');

        //reponse payload
        $payload = [
            'invoice' => $invoice,
            'tags' => $tags,
            'categories' => $categories,
        ];

        //show the form
        return new CreateCloneResponse($payload);
    }

    /**
     * show the form for cloning an invoice
     * @param object InvoiceClone instance of the request validation
     * @param object CloneInvoiceRepository instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function storeClone(InvoiceClone $request, CloneInvoiceRepository $cloneinvoicerepo, $id)
    {

        //get the invoice
        $invoice = \App\Models\Invoice::Where('bill_invoiceid', $id)->first();

        //clone data
        $data = [
            'invoice_id' => $id,
            'client_id' => request('bill_clientid'),
            'project_id' => request('bill_projectid'),
            'invoice_date' => request('bill_date'),
            'invoice_due_date' => request('bill_due_date'),
            'bill_categoryid' => request('bill_categoryid'),
            'return' => 'id',
        ];

        //clone invoice
        if (!$invoice_id = $cloneinvoicerepo->clone($data)) {
            abort(409, __('lang.cloning_failed'));
        }

        //reponse payload
        $payload = [
            'id' => $invoice_id,
        ];

        //show the form
        return new StoreCloneResponse($payload);
    }

    /**
     * email a pdf versio to the client
     * @return \Illuminate\Http\Response
     */
    public function emailClient()
    {

        //validate the invoice exists
        $invoice = \App\Models\Invoice::Where('bill_invoiceid', request('id'))->first();

        //notice
        $jsondata['notification'] = array('type' => 'success', 'value' => '[TODO]');

        //response
        return response()->json($jsondata);
    }

    /**
     * Show the form for editing the specified invoice
     * @param  int  $invoice invoice id
     * @return \Illuminate\Http\Response
     */
    public function recurringSettings($id)
    {

        //get the project
        $invoice = \App\Models\Invoice::Where('bill_invoiceid', $id)->first();

        //reponse payload
        $payload = [
            'page' => $this->pageSettings('edit'),
            'invoice' => $invoice,
        ];

        //response
        return new RecurringSettingsResponse($payload);
    }

    /**
     * Update recurring settings
     * @param object InvoiceRecurrringSettings instance of the request validation object
     * @param  int  $invoice invoice id
     * @return \Illuminate\Http\Response
     */
    public function recurringSettingsUpdate(InvoiceRecurrringSettings $request, $id)
    {

        //get project
        $invoices = $this->invoicerepo->search($id);
        $invoice = $invoices->first();

        //update
        $invoice->bill_recurring = 'yes';
        $invoice->bill_recurring_duration = request('bill_recurring_duration');
        $invoice->bill_recurring_period = request('bill_recurring_period');
        $invoice->bill_recurring_cycles = request('bill_recurring_cycles');
        $invoice->bill_recurring_next = request('bill_recurring_next');

        //refresh invoice
        $this->invoicerepo->refreshInvoice($invoice);

        //reponse payload
        $payload = [
            'page' => $this->pageSettings('edit'),
            'invoices' => $invoices,
        ];

        //response
        return new UpdateResponse($payload);
    }

    /**
     * stop an invoice from recurring
     * @return \Illuminate\Http\Response
     */
    public function stopRecurring()
    {

        //get the invoice
        $invoice = \App\Models\Invoice::Where('bill_invoiceid', request()->route('invoice'))->first();

        //update the invoice
        $invoice->bill_recurring = 'no';
        $invoice->save();

        //get refreshed invoice
        $invoices = $this->invoicerepo->search(request()->route('invoice'));

        //reponse payload
        $payload = [
            'invoices' => $invoices,
        ];

        //show the form
        return new UpdateResponse($payload);
    }

    /**
     * create line items for ths invoice (from submitted expense items)
     * @param int $bill_invoiceid invoice id
     * @return null
     */
    public function expensesPayload($bill_invoiceid)
    {

        $invoice = \App\Models\Invoice::Where('bill_invoiceid', $bill_invoiceid)->first();

        //do we have an expense in the payload?
        if (is_array(request('expense_payload'))) {
            foreach (request('expense_payload') as $expense_id) {
                //get the expense
                if ($expense = \App\Models\Expense::Where('expense_id', $expense_id)->first()) {

                    //create a new invoice line item
                    $data['lineitem_description'] = $expense->expense_description;
                    $data['lineitem_rate'] = $expense->expense_amount;
                    $data['lineitem_unit'] = __('lang.item');
                    $data['lineitem_quantity'] = 1;
                    $data['lineitem_total'] = $expense->expense_amount;
                    $data['lineitemresource_linked_type'] = 'expense';
                    $data['lineitemresource_linked_type'] = $expense_id;
                    $data['lineitemresource_type'] = 'invoice';
                    $data['lineitemresource_id'] = $bill_invoiceid;
                    $this->lineitemrepo->create($data);

                    //update expense with invoice id and mark as invoiced
                    $expense->expense_billing_status = 'invoiced';
                    $expense->expense_billable_invoiceid = $bill_invoiceid;
                    $expense->save();
                }
            }
        }
    }

    /**
     * create line items for ths invoice (from submitted expense items)
     * @param object StripePaymentRepository instance of the repository
     * @param object InvoiceRepository instance of the repository
     * @param int $id client id
     */
    public function paymentStripe(StripePaymentRepository $striperepo, InvoiceRepository $invoicerepo, $id)
    {

        //get invoice
        $invoices = $invoicerepo->search($id);
        $invoice = $invoices->first();

        //payment payload
        $data = [
            'amount' => $invoice->invoice_balance,
            'currency' => config('system.settings_stripe_currency'),
            'invoice_id' => $invoice->bill_invoiceid,
            'cancel_url' => url('invoices/' . $invoice->bill_invoiceid), //in future, this can be bulk payments page
        ];

        //create a new stripe session
        $session_id = $striperepo->onetimePayment($data);

        //reponse payload
        $payload = [
            'session_id' => $session_id,
        ];

        //show the view
        return new StripePaymentResponse($payload);
    }

    /**
     * create line items for ths invoice (from submitted expense items)
     * @param object PaypalPaymentRepository instance of the repository
     * @param object InvoiceRepository instance of the repository
     * @param int $id client id
     */
    public function paymentPaypal(PaypalPaymentRepository $paypalrepo, InvoiceRepository $invoicerepo, $id)
    {

        //get invoice
        $invoices = $invoicerepo->search($id);
        $invoice = $invoices->first();

        //payment payload
        $data = [
            'amount' => $invoice->invoice_balance,
            'currency' => config('system.settings_paypal_currency'),
            'item_name' => __('lang.invoice_payment'),
            'invoice_id' => $invoice->bill_invoiceid,
            'ipn_url' => url('/api/paypal/ipn'),
            'cancel_url' => url('invoices/' . $invoice->bill_invoiceid), //in future, this can be bulk payments page
        ];

        //create a new paypal session
        $session_id = $paypalrepo->onetimePayment($data);

        //more data
        $data['thank_you_url'] = url('payments/thankyou?session_id=' . $session_id);
        $data['session_id'] = $session_id;

        //reponse payload
        $payload = [
            'paypal' => $data,
        ];

        //show the view
        return new PaypalPaymentResponse($payload);
    }

    /**
     * create line items for ths invoice (from submitted expense items)
     * @param object PaypalPaymentRepository instance of the repository
     * @param object InvoiceRepository instance of the repository
     * @param int $id client id
     */
    public function paymentRazorpay(RazorpayPaymentRepository $razorpayrepo, InvoiceRepository $invoicerepo, $id)
    {

        //get invoice
        $invoices = $invoicerepo->search($id);
        $invoice = $invoices->first();

        //payment payload
        $payload = [
            'amount' => $invoice->invoice_balance,
            'unit_amount' => $invoice->invoice_balance * 100, //lowest unit (e.g. cents)
            'currency' => config('system.settings_razorpay_currency'),
            'invoice_id' => $invoice->bill_invoiceid,
        ];

        //create a new razorpay session
        if (!$order_id = $razorpayrepo->onetimePayment($payload)) {
            abort(409, __('lang.error_request_could_not_be_completed'));
        }

        //more data
        $payload['thank_you_url'] = url('payments/thankyou?gateway=razorpay&order_id=' . $order_id);
        $payload['order_id'] = $order_id;
        $payload['company_name'] = config('system.settings_company_name');
        $payload['description'] = __('lang.invoice_payment');
        $payload['image'] = runtimeLogoSmall();
        $payload['thankyou_url'] = url('/payments/thankyou/razorpay');
        $payload['client_name'] = $invoice->client_company_name;
        $payload['client_email'] = auth()->user()->email;
        $payload['key'] = config('system.settings_razorpay_keyid');

        //show the view
        return new RazorpayPaymentResponse($payload);
    }

    /**
     * create line items for ths invoice (from submitted expense items)
     * @param object PaypalPaymentRepository instance of the repository
     * @param object InvoiceRepository instance of the repository
     * @param int $id client id
     */
    public function paymentMollie(MolliePaymentRepository $mollierepo, InvoiceRepository $invoicerepo, $id)
    {

        //get invoice
        $invoices = $invoicerepo->search($id);
        $invoice = $invoices->first();

        //payment payload
        $payload = [
            'amount' => $invoice->invoice_balance,
            'currency' => config('system.settings_mollie_currency'),
            'invoice_id' => $invoice->bill_invoiceid,
            'thank_you_url' => url('payments/thankyou?gateway=mollie'),
            'webhooks_url' => url('/api/mollie/webhooks'),
        ];

        //create a new razorpay session
        if (!$mollie_url = $mollierepo->onetimePayment($payload)) {
            abort(409, __('lang.error_request_could_not_be_completed'));
        }

        //payload
        $payload = [
            'redirect_url' => $mollie_url,
        ];

        //redirect user
        return new MolliePaymentResponse($payload);
    }

    /**
     * Show the form for changing estimate category
     * @param object CategoryRepository instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function convertToAvoir($id, CategoryRepository $categoryrepo)
    {

        $categories = $categoryrepo->get('avoir');

        //reponse payload
        $payload = [
            'invoice_id' => $id,
            'categories' => $categories,
        ];

        //show the form 
        return new ConvertToAvoirResponse($payload);
    }
    /**
     * Show the form for changing estimate category
     * @param object CategoryRepository instance of the repository
     * @return \Illuminate\Http\Response
     */
    public function convertToAvoirAction(DestroyRepository $destroyrepo, $id)
    {

        //order of invoices to display in table
        $invoices = Invoice::where('bill_type', 'avoir')->get();
        if ($invoices->count() > 0) {
            $invoices_count = $invoices->max('bill_order');
            $order_bill = $invoices_count + 1;
        } else {
            $order_bill = 1;
        }

        //get the invoice
        $invoice = \App\Models\Invoice::Where('bill_invoiceid', $id)->first();
        //get the line items
        $taxes = \App\Models\Tax::Where('taxresource_type', 'invoice')->where('taxresource_id', $id)->get();

        //get the line items
        $lines = \App\Models\Lineitem::Where('lineitemresource_type', 'invoice')->where('lineitemresource_id', $id)->get();

        $payments = \App\Models\Payment::Where('payment_invoiceid', $id)->get();

        //create an invocie
        $avoir = new \App\Models\Invoice();
        $avoir->bill_clientid = $invoice->bill_clientid;
        $avoir->bill_projectid = $invoice->bill_projectid;
        $avoir->bill_creatorid = auth()->id();
        $avoir->bill_date = request('bill_date');
        $avoir->bill_due_date = request('bill_due_date');
        $avoir->bill_categoryid = request('bill_categoryid');
        $avoir->bill_subtotal = $invoice->bill_subtotal;
        $avoir->bill_discount_type = $invoice->bill_discount_type;
        $avoir->bill_discount_percentage = $invoice->bill_discount_percentage;
        $avoir->bill_discount_amount = $invoice->bill_discount_amount;
        $avoir->bill_amount_before_tax = $invoice->bill_amount_before_tax;
        $avoir->bill_tax_type = $invoice->bill_tax_type;
        $avoir->bill_tax_total_percentage = $invoice->bill_tax_total_percentage;
        $avoir->bill_tax_total_amount = $invoice->bill_tax_total_amount;
        $avoir->bill_final_amount = $invoice->bill_final_amount;
        $avoir->bill_adjustment_description = $invoice->bill_adjustment_description;
        $avoir->bill_adjustment_amount = $invoice->bill_adjustment_amount;
        $avoir->bill_notes = '';
        $avoir->bill_terms = config('system.settings_avoirs_default_terms_conditions');
        $avoir->bill_footer = config('system.settings_avoirs_footer');
        $avoir->bill_status = 'draft';
        $avoir->bill_invoice_type = 'onetime';
        $avoir->bill_type = 'avoir';
        $avoir->bill_visibility = 'visible';
        $avoir->bill_order = $order_bill;
        //$avoir->bill_footer = $invoice->bill_footer;
        $avoir->related_invoice_id = $id;
        
        //estimate notes
        if (request('copy_invoice_notes') == 'on') {
            $avoir->bill_notes = $invoice->bill_notes;
        }

        //estimate terms
        if (request('copy_invoice_terms') == 'on') {
            $avoir->bill_terms = $invoice->bill_terms;
        }

        //estimate terms
        if (request('copy_invoice_footer') == 'on') {
            $avoir->bill_footer = $invoice->bill_footer;
        }
        
        $avoir->save();

        //clone line items
        foreach ($lines as $line) {
            $lineitem = $line->replicate();
            $lineitem->lineitem_created = now();
            $lineitem->lineitem_updated = now();
            $lineitem->lineitemresource_type = 'invoice';
            $lineitem->lineitemresource_id = $avoir->bill_invoiceid;
            $lineitem->save();
        }

        //clone taxes
        foreach ($taxes as $tax) {
            $newtax = $tax->replicate();
            $newtax->tax_created = now();
            $newtax->tax_updated = now();
            $newtax->taxresource_type = 'invoice';
            $newtax->taxresource_id = $avoir->bill_invoiceid;
            $newtax->save();
        }

        // //clone payments
        // foreach ($payments as $payment) {
        //     $newpayment = $payment->replicate();
        //     $newpayment->payment_created = now();
        //     $newpayment->payment_updated = now();
        //     $newpayment->payment_invoiceid = $avoir->bill_invoiceid;
        //     $newpayment->save();
        // }

        //delete original estimate
        if (request('delete_original_invoice') == 'on') {
            $destroyrepo->destroyInvoice($id);
        }

        //redirect to new invoice
        $jsondata = [];
        $jsondata['redirect_url'] = url("/avoirs/" . $avoir->bill_invoiceid);
        return response()->json($jsondata);
    }


    /**
     * basic settings for the invoices list page
     * @return array
     */
    private function pageSettings($section = '', $data = array())
    {

        //common settings
        $page = [
            'crumbs' => [
                __('lang.sales'),
                __('lang.invoices'),
            ],
            'crumbs_special_class' => 'list-pages-crumbs',
            'page' => 'invoices',
            'no_results_message' => !empty(request('filter_bill_status')) ? (in_array('due', request('filter_bill_status')) ? __('lang.no_results_found_invoice_due') : __('lang.no_results_found_invoice_overdue')) : __('lang.no_results_found_invoice'),
            'no_results_sub_message' => __('lang.no_results_sub_invoice'),
            'mainmenu_invoices' => 'active',
            'mainmenu_sales' => 'active',
            'submenu_invoices' => 'active',
            'sidepanel_id' => 'sidepanel-filter-invoices',
            'dynamic_search_url' => url('invoices/search?action=search&invoiceresource_id=' . request('invoiceresource_id') . '&invoiceresource_type=' . request('invoiceresource_type')),
            'add_button_classes' => 'add-edit-invoice-button',
            'load_more_button_route' => 'invoices',
            'source' => 'list',
        ];
        //default modal settings (modify for sepecif sections)
        $page += [
            'add_modal_title' => __('lang.add_invoice'),
            'add_modal_create_url' => url('invoices/create?invoiceresource_id=' . request('invoiceresource_id') . '&invoiceresource_type=' . request('invoiceresource_type')),
            'add_modal_action_url' => url('invoices?invoiceresource_id=' . request('invoiceresource_id') . '&invoiceresource_type=' . request('invoiceresource_type')),
            'add_modal_action_ajax_class' => '',
            'add_modal_action_ajax_loading_target' => 'commonModalBody',
            'add_modal_action_method' => 'POST',
        ];

        //invoices list page
        if ($section == 'invoices') {
            $page += [
                'meta_title' => __('lang.invoices'),
                'heading' => __('lang.invoices'),
                'sidepanel_id' => 'sidepanel-filter-invoices',
            ];
            if (request('source') == 'ext') {
                $page += [
                    'list_page_actions_size' => 'col-lg-12',
                ];
            }
            return $page;
        }

        //invoice page
        if ($section == 'invoice') {
            //adjust
            $page['page'] = 'invoice';
            //add
            $page += [
                'crumbs' => [
                    __('lang.invoice'),
                ],
                'crumbs_special_class' => 'main-pages-crumbs',
                'meta_title' => __('lang.invoice') . ' #' . $data->formatted_bill_invoiceid,
                'heading' => __('lang.project') . ' - ' . $data->project_title,
                'bill_invoiceid' => request()->segment(2),
                'source_for_filter_panels' => 'ext',
                'section' => 'overview',
            ];

            //crumbs
            $page['crumbs'] = [
                __('lang.sales'),
                __('lang.invoices'),
                $data['formatted_bill_invoiceid'],
            ];

            //ajax loading and tabs
            return $page;
        }

        //create new resource
        if ($section == 'create') {
            $page += [
                'section' => 'create',
            ];
            return $page;
        }

        //edit new resource
        if ($section == 'edit') {
            $page['mode'] = 'editing';
            $page += [
                'section' => 'edit',
            ];
            return $page;
        }

        //return
        return $page;
    }

    /**
     * data for the stats widget
     * @return array
     */
    private function statsWidget($data = array())
    {

        //stats
        $count_all = $this->invoicerepo->search('', ['stats' => 'count-all'])->count();
        $count_due = $this->invoicerepo->search('', ['stats' => 'count-due'])->count();
        $count_overdue = $this->invoicerepo->search('', ['stats' => 'count-overdue'])->count();

        $sum_all = $this->invoicerepo->search('', ['stats' => 'sum-all']);
        $sum_payments = $this->invoicerepo->search('', ['stats' => 'sum-payments']);
        $sum_due_balances = $this->invoicerepo->search('', ['stats' => 'sum-due-balances']);
        $sum_overdue_balances = $this->invoicerepo->search('', ['stats' => 'sum-overdue-balances']);

        //default values
        $stats = [
            [
                'value' => runtimeMoneyFormat($sum_all),
                'title' => __('lang.invoices') . " ($count_all)",
                'percentage' => '100%',
                'color' => 'bg-info',
            ],
            [
                'value' => runtimeMoneyFormat($sum_payments),
                'title' => __('lang.payments'),
                'percentage' => '100%',
                'color' => 'bg-success',
            ],

            [
                'value' => runtimeMoneyFormat($sum_due_balances),
                'title' => __('lang.due') . " ($count_due)",
                'percentage' => '100%',
                'color' => 'bg-warning',
            ],
            [
                'value' => runtimeMoneyFormat($sum_overdue_balances),
                'title' => __('lang.overdue') . " ($count_overdue)",
                'percentage' => '100%',
                'color' => 'bg-danger',
            ],
        ];
        //return
        return $stats;
    }
}
