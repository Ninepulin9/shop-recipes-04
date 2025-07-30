<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Categories_member;
use App\Models\User;
use App\Models\UsersCategories;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class Member extends Controller
{
    public function member()
    {
        $data['function_key'] = __FUNCTION__;
        return view('member.index', $data);
    }

    public function memberlistData()
    {
        $data = [
            'status' => false,
            'message' => '',
            'data' => []
        ];
        $table = User::with('categories.categories')->where('is_member', 1)->get();

        if (count($table) > 0) {
            $info = [];
            foreach ($table as $rs) {
                $action = '<a href="' . route('memberEdit', $rs->id) . '" class="btn btn-sm btn-outline-primary" title="แก้ไข"><i class="bx bx-edit-alt"></i></a>
                <button type="button" data-id="' . $rs->id . '" class="btn btn-sm btn-outline-danger deleteTable" title="ลบ"><i class="bx bxs-trash"></i></button>';
                $categoryName = '-';
                if (isset($rs['categories']->categories)) {
                    $categoryName = $rs['categories']['categories']->name;
                }
                $info[] = [
                    'name' => $rs->name,
                    'categories' => $categoryName,
                    'email' => $rs->email,
                    'tel' => $rs->tel,
                    'action' => $action
                ];
            }
            $data = [
                'data' => $info,
                'status' => true,
                'message' => 'success'
            ];
        }
        return response()->json($data);
    }

    public function memberDelete(Request $request)
    {
        $data = [
            'status' => false,
            'message' => 'ลบข้อมูลไม่สำเร็จ',
        ];
        $id = $request->input('id');
        if ($id) {
            $delete = User::find($id);
            if ($delete->delete()) {
                $data = [
                    'status' => true,
                    'message' => 'ลบข้อมูลเรียบร้อยแล้ว',
                ];
            }
        }

        return response()->json($data);
    }

    public function memberCreate()
    {
        $data['function_key'] = 'member';
        $data['categories'] = Categories_member::get();
        return view('member.create', $data);
    }

    public function memberEdit($id)
    {
        $function_key = 'member';
        $categories = Categories_member::get();
        $info = User::with('categories')->find($id);

        return view('member.edit', compact('info', 'function_key', 'categories'));
    }

    public function memberSave(Request $request)
    {
        $input = $request->input();
        if (!isset($input['id'])) {
            $table = new User();
            $table->name = $input['name'];
            $table->email = $input['email'];
            $table->tel = $input['tel'];
            $table->role = 'admin';
            $table->email_verified_at = now();
            $table->password = Hash::make('123456789');
            $table->remember_token = null;
            $table->is_member = 1;
            if ($table->save()) {
                $categories = new UsersCategories();
                $categories->users_id = $table->id;
                $categories->categories_id = $input['categories_id'];
                if ($categories->save()) {
                    return redirect()->route('member')->with('success', 'บันทึกรายการเรียบร้อยแล้ว');
                }
            }
        } else {
            $table = User::find($input['id']);
            $table->name = $input['name'];
            $table->email = $input['email'];
            $table->tel = $input['tel'];
            if ($table->save()) {
            $categories = UsersCategories::where('users_id', $input['id'])->first();

              if (!$categories) {
                // ถ้ายังไม่มี record ให้สร้างใหม่
                $categories = new UsersCategories();
                $categories->users_id = $input['id'];
               }

            $categories->categories_id = $input['categories_id'] ?? null; // รองรับค่าว่าง
            $categories->save();

            return redirect()->route('member')->with('success', 'บันทึกรายการเรียบร้อยแล้ว');
        }
        }
        return redirect()->route('member')->with('error', 'ไม่สามารถบันทึกข้อมูลได้');
    }
}
