<?php

namespace App\Http\Controllers;

use App\Events\OrderCreated;
use App\Http\Controllers\admin\Category;
use App\Http\Controllers\Controller;
use App\Models\Categories;
use App\Models\LogStock;
use App\Models\Menu;
use App\Models\MenuOption;
use App\Models\MenuStock;
use App\Models\MenuTypeOption;
use App\Models\Orders;
use App\Models\OrdersDetails;
use App\Models\OrdersOption;
use App\Models\Promotion;
use App\Models\Stock;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class Main extends Controller
{
    public function index(Request $request)
    {
        $table_id = $request->input('table');
        if ($table_id) {
            $table = Table::where('table_number', $table_id)->first();
            session(['table_id' => $table_id]);
        }
        $promotion = Promotion::where('is_status', 1)->get();
        $category = Categories::has('menu')->with('files')->get();
        return view('users.main_page', compact('category', 'promotion'));
    }

    public function detail($id)
    {
        $item = [];
        $menu = Menu::where('categories_id', $id)->with('files')->orderBy('created_at', 'asc')->get();
        foreach ($menu as $key => $rs) {
            $item[$key] = [
                'id' => $rs->id,
                'category_id' => $rs->categories_id,
                'name' => $rs->name,
                'detail' => $rs->detail,
                'base_price' => $rs->base_price,
                'files' => $rs['files']
            ];
            $typeOption = MenuTypeOption::where('menu_id', $rs->id)->get();
            if (count($typeOption) > 0) {
                foreach ($typeOption as $typeOptions) {
                    $optionItem = [];
                    $option = MenuOption::where('menu_type_option_id', $typeOptions->id)->get();
                    foreach ($option as $options) {
                        $optionItem[] = (object)[
                            'id' => $options->id,
                            'name' => $options->type,
                            'price' => $options->price
                        ];
                    }
                    $item[$key]['option'][$typeOptions->name] = [
                        'is_selected' => $typeOptions->is_selected,
                        'amout' => $typeOptions->amout,
                        'items' =>  $optionItem
                    ];
                }
            } else {
                $item[$key]['option'] = [];
            }
        }
        $menu = $item;
        return view('users.detail_page', compact('menu'));
    }

    public function order()
    {
        return view('users.list_page');
    }

   public function SendOrder(Request $request)
{
    $data = [
        'status' => false,
        'message' => 'สั่งออเดอร์ไม่สำเร็จ',
    ];
    
    // Debug: ดูข้อมูลที่ส่งมา
    \Log::info('Order data received: ' . json_encode($request->all()));
    
    $orderData = $request->input('cart');
    $remark = $request->input('remark');
    
    if (empty($orderData)) {
        $data['message'] = 'ไม่มีข้อมูลออเดอร์';
        return response()->json($data);
    }
    
    $item = array();
    $menu_id = array();
    $categories_id = array();
    $total = 0;
    
    foreach ($orderData as $key => $order) {
        $item[$key] = [
            'menu_id' => $order['id'],
            'quantity' => $order['amount'],
            'price' => $order['total_price'],
            'note' => $order['note'] ?? ''
        ];
        
        if (!empty($order['options'])) {
            foreach ($order['options'] as $rs) {
                $item[$key]['option'][] = $rs['id'];
            }
        } else {
            $item[$key]['option'] = [];
        }
        
        $total = $total + $order['total_price'];
        $menu_id[] = $order['id'];
    }
    
    // Debug: ดูข้อมูลที่ประมวลผลแล้ว
    \Log::info('Processed items: ' . json_encode($item));
    \Log::info('Total: ' . $total);
    \Log::info('Table ID from session: ' . session('table_id'));
    
    $menu_id = array_unique($menu_id);
    foreach ($menu_id as $rs) {
        $menu = Menu::find($rs);
        if ($menu) {
            $categories_id[] = $menu->categories_member_id ?? $menu->categories_id;
        }
    }
    $categories_id = array_unique($categories_id);

    if (!empty($item)) {
        try {
            $order = new Orders();
            $order->table_id = session('table_id') ?? 1; // ถ้าไม่มี session ให้ใช้โต๊ะ 1
            $order->total = $total;
            $order->remark = $remark ?? '';
            $order->status = 1;
            
            \Log::info('Creating order: ' . json_encode([
                'table_id' => $order->table_id,
                'total' => $order->total,
                'remark' => $order->remark,
                'status' => $order->status
            ]));
            
            if ($order->save()) {
                \Log::info('Order created with ID: ' . $order->id);
                
                foreach ($item as $rs) {
                    $orderdetail = new OrdersDetails();
                    $orderdetail->order_id = $order->id;
                    $orderdetail->menu_id = $rs['menu_id'];
                    $orderdetail->quantity = $rs['quantity'];
                    $orderdetail->price = $rs['price'];
                    $orderdetail->remark = $rs['note'];
                    
                    if ($orderdetail->save()) {
                        \Log::info('Order detail saved: ' . json_encode([
                            'order_id' => $orderdetail->order_id,
                            'menu_id' => $orderdetail->menu_id,
                            'quantity' => $orderdetail->quantity,
                            'price' => $orderdetail->price
                        ]));
                        
                        // บันทึก options
                        if (!empty($rs['option'])) {
                            foreach ($rs['option'] as $option_id) {
                                $orderOption = new OrdersOption();
                                $orderOption->order_detail_id = $orderdetail->id;
                                $orderOption->option_id = $option_id;
                                $orderOption->save();
                            }
                        }
                        
                        // จัดการ stock (ถ้ามี)
                        if (!empty($rs['option'])) {
                            foreach ($rs['option'] as $option_id) {
                                try {
                                    $menuStock = MenuStock::where('menu_option_id', $option_id)->get();
                                    if ($menuStock->isNotEmpty()) {
                                        foreach ($menuStock as $stock_rs) {
                                            $stock = Stock::find($stock_rs->stock_id);
                                            if ($stock) {
                                                $stock->amount = $stock->amount - ($stock_rs->amount * $rs['quantity']);
                                                if ($stock->save()) {
                                                    $log_stock = new LogStock();
                                                    $log_stock->stock_id = $stock_rs->stock_id;
                                                    $log_stock->order_id = $order->id;
                                                    $log_stock->menu_option_id = $option_id;
                                                    $log_stock->old_amount = $stock_rs->amount;
                                                    $log_stock->amount = ($stock_rs->amount * $rs['quantity']);
                                                    $log_stock->status = 2;
                                                    $log_stock->save();
                                                }
                                            }
                                        }
                                    }
                                } catch (\Exception $e) {
                                    \Log::warning('Stock management error: ' . $e->getMessage());
                                }
                            }
                        }
                    }
                }
                
                // ส่ง event notification
                try {
                    $order_event = [
                        'is_member' => 0,
                        'text' => '📦 มีออเดอร์ใหม่ โต๊ะ ' . session('table_id')
                    ];
                    event(new OrderCreated($order_event));
                    
                    if (!empty($categories_id)) {
                        foreach ($categories_id as $cat_id) {
                            $order_event = [
                                'is_member' => 1,
                                'categories_id' => $cat_id,
                                'text' => '📦 มีออเดอร์ใหม่ โต๊ะ ' . session('table_id')
                            ];
                            event(new OrderCreated($order_event));
                        }
                    }
                } catch (\Exception $e) {
                    \Log::warning('Event notification error: ' . $e->getMessage());
                }
                
                $data = [
                    'status' => true,
                    'message' => 'สั่งออเดอร์เรียบร้อยแล้ว',
                    'order_id' => $order->id
                ];
            } else {
                \Log::error('Failed to save order');
                $data['message'] = 'ไม่สามารถบันทึกออเดอร์ได้';
            }
        } catch (\Exception $e) {
            \Log::error('Order creation error: ' . $e->getMessage());
            $data['message'] = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    } else {
        $data['message'] = 'ไม่มีรายการสั่งซื้อ';
    }
    
    \Log::info('Final response: ' . json_encode($data));
    return response()->json($data);
}

    public function sendEmp()
    {
        event(new OrderCreated(['ลูกค้าเรียกจากโต้ะที่ ' . session('table_id')]));
    }
}
