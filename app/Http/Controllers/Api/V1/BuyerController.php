<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Requests\CreateBuyerData;
use App\Data\Requests\UpdateBuyerData;
use App\Data\Resources\BuyerData;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use Illuminate\Http\Request;
use Spatie\LaravelData\CursorPaginatedDataCollection;

class BuyerController extends Controller
{
    /** @return CursorPaginatedDataCollection<int, BuyerData> */
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $query = Buyer::query()->orderByDesc('created_at')->orderByDesc('id');
        $tin = $request->query('tin');
        if (is_string($tin) && $tin !== '') {
            $query->where('tin', $tin);
        }

        return BuyerData::collect($query->cursorPaginate(50), CursorPaginatedDataCollection::class);
    }

    public function store(CreateBuyerData $data): BuyerData
    {
        return BuyerData::fromModel(Buyer::create($data->toArray()))->wrap('data');
    }

    public function show(Buyer $buyer): BuyerData
    {
        return BuyerData::fromModel($buyer)->wrap('data');
    }

    public function update(UpdateBuyerData $data, Buyer $buyer): BuyerData
    {
        $buyer->update($data->toArray());

        return BuyerData::fromModel($buyer->refresh())->wrap('data');
    }
}
