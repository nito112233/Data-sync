<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['external_id','name','email','phone'];

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }
}