<?php

namespace App\Http\Controllers\Api;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Account\Admin\Admin;
use App\Models\Order\Shipment;
use App\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShipmentController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\ValidatesOperationalInput;
    public function __construct(private ShipmentService $shipments) { $this->middleware('auth:sanctum'); }

    private function scope(Request $request)
    {
        $query = Shipment::query();
        if (!$request->user() instanceof Admin) {
            $query->where('shipper_id', $request->user()->id)->where('shipping_provider_id', $request->user()->shipping_provider_id);
        }
        return $query;
    }

    private function owned(Request $request, int $id): Shipment
    {
        return $this->scope($request)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $data = $this->validated($request, ['status' => ['nullable', Rule::in(array_column(ShipmentStatus::cases(), 'value'))],
            'q' => 'nullable|string|max:100', 'limit' => 'nullable|integer|min:1|max:100']);
        $page = $this->scope($request)->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['q'] ?? null, fn ($q, $search) => $q->where('tracking_code', 'like', '%' . $search . '%'))
            ->with(['order.items', 'order.shop:id,name', 'order.receivedAddress', 'order.pickupAddress', 'order.payments', 'shipper.provider', 'histories'])
            ->orderByDesc('id')->paginate($data['limit'] ?? 15);
        $page->getCollection()->transform(fn ($shipment) => $this->shipments->present($shipment));
        return response()->json(['status' => true, 'data' => $page]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['status' => true, 'data' => $this->shipments->present($this->owned($request, $id))]);
    }

    public function dashboard(Request $request)
    {
        $counts = $this->scope($request)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        return response()->json(['status' => true, 'data' => ['total' => $counts->sum(), 'counts' => $counts]]);
    }

    public function history(Request $request)
    {
        $ids = $this->scope($request)->select('id');
        $history = \App\Models\Delivery\ShipmentHistory::whereIn('shipment_id', $ids)
            ->with('shipment:id,tracking_code')->orderByDesc('id')->paginate(30);
        return response()->json(['status' => true, 'data' => $history]);
    }

    public function assign(Request $request, int $id) { return $this->change($request, $id, 'assign'); }
    public function update(Request $request, int $id) { return $this->change($request, $id, 'status'); }
    public function collect(Request $request, int $id) { return $this->change($request, $id, 'cod'); }
    public function requestReturn(Request $request, int $id) { return $this->change($request, $id, 'request-return'); }

    private function change(Request $request, int $id, string $action)
    {
        $rules = ['request_key' => 'required|uuid', 'expected_version' => 'required|integer|min:0', 'note' => 'nullable|string|max:1000'];
        $rules += match ($action) {
            'assign' => ['shipper_id' => 'required|integer|min:1'],
            'status' => ['status' => ['required', Rule::in(array_column(ShipmentStatus::cases(), 'value'))], 'failed_reason' => 'nullable|required_if:status,DELIVERY_FAILED|string|max:1000'],
            'cod' => ['amount' => 'required|numeric|min:0', 'receipt_reference' => 'required|string|min:6|max:100', 'cash_received' => 'required|accepted'],
            'request-return' => ['note' => 'required|string|min:5|max:1000'],
        };
        // Array union above retains the optional note rule; require it for incident handling.
        if ($action === 'request-return') $rules['note'] = 'required|string|min:5|max:1000';
        $data = $this->validated($request, $rules);
        $shipment = $this->shipments->mutate($this->owned($request, $id), $request->user(), $action, $data);
        return response()->json(['status' => true, 'message' => 'Đã cập nhật vận đơn.', 'data' => $this->shipments->present($shipment)]);
    }
}
