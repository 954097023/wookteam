<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Module\Base;
use App\Module\Chat;
use App\Module\Users;
use DB;
use Request;

/**
 * @apiDefine chat
 *
 * 聊天
 */
class ChatController extends Controller
{
    public function __invoke($method, $action = '')
    {
        $app = $method ? $method : 'main';
        if ($action) {
            $app .= "__" . $action;
        }
        return (method_exists($this, $app)) ? $this->$app() : Base::ajaxError("404 not found (" . str_replace("__", "/", $app) . ").");
    }

    /**
     * 对话列表
     */
    public function dialog__lists()
    {
        $user = Users::authE();
        if (Base::isError($user)) {
            return $user;
        } else {
            $user = $user['data'];
        }
        //
        $lists = Base::DBC2A(DB::table('chat_dialog')
            ->where(function ($query) use ($user) {
                return $query->where('user1', $user['username'])->where('del1', 0);
            })
            ->orWhere(function ($query) use ($user) {
                return $query->where('user2', $user['username'])->where('del2', 0);
            })
            ->orderByDesc('lastdate')
            ->take(200)
            ->get());
        if (count($lists) <= 0) {
            return Base::retError('暂无对话记录');
        }
        $hasUnset = false;
        foreach ($lists AS $key => $item) {
            $tmpUserInfo = Users::username2basic($item['user1'] == $user['username'] ? $item['user2'] : $item['user1']);
            if (empty($tmpUserInfo)) {
                DB::table('chat_dialog')->where('id', $item['id'])->update(['del1' => 1, 'del2' => 1]);
                $hasUnset = true;
                unset($lists[$key]);
                continue;
            }
            $lists[$key] = array_merge($item, $tmpUserInfo);
            $lists[$key]['lastdate'] = $item['lastdate'] ?: $item['indate'];
            $unread = 0;
            if ($item['user1'] == $user['username']) $unread+= $item['unread1'];
            if ($item['user2'] == $user['username']) $unread+= $item['unread2'];
            $lists[$key]['unread'] = $unread;
        }
        if ($hasUnset) {
            $lists = array_values($lists);
        }
        return Base::retSuccess('success', $lists);
    }

    /**
     * 清空聊天记录（删除对话）
     *
     * @apiParam {String} username             用户名
     * @apiParam {Number} [delete]             是否删除对话，1:是
     */
    public function dialog__clear()
    {
        $user = Users::authE();
        if (Base::isError($user)) {
            return $user;
        } else {
            $user = $user['data'];
        }
        //
        $delete = intval(Request::input('delete'));
        $res = Chat::openDialog($user['username'], trim(Request::input('username')));
        if (Base::isError($res)) {
            return $res;
        }
        $dialog = $res['data'];
        $lastMsg = Base::DBC2A(DB::table('chat_msg')
            ->select(['id'])
            ->where('did', $dialog['id'])
            ->orderByDesc('indate')
            ->orderByDesc('id')
            ->first());
        $upArray = [
            ($dialog['recField'] == 1 ? 'lastid2' : 'lastid1') => $lastMsg ? $lastMsg['id'] : 0
        ];
        if ($delete === 1) {
            $upArray[($dialog['recField'] == 1 ? 'del2' : 'del1')] = 1;
        }
        DB::table('chat_dialog')->where('id', $dialog['id'])->update($upArray);
        Chat::forgetDialog($dialog['user1'], $dialog['user2']);
        return Base::retSuccess($delete ? '删除成功！' : '清除成功！');
    }

    /**
     * 消息列表
     *
     * @apiParam {String} username             用户名
     * @apiParam {Number} [page]               当前页，默认:1
     * @apiParam {Number} [pagesize]           每页显示数量，默认:20，最大:100
     */
    public function message__lists()
    {
        $user = Users::authE();
        if (Base::isError($user)) {
            return $user;
        } else {
            $user = $user['data'];
        }
        //
        $res = Chat::openDialog($user['username'], trim(Request::input('username')));
        if (Base::isError($res)) {
            return $res;
        }
        $dialog = $res['data'];
        $lastid = $dialog[($dialog['recField'] == 1 ? 'lastid2' : 'lastid1')];
        $whereArray = [];
        $whereArray[] = ['did', '=', $dialog['id']];
        if ($lastid > 0) {
            $whereArray[] = ['id', '>', $lastid];
        }
        $lists = DB::table('chat_msg')
            ->where($whereArray)
            ->orderByDesc('indate')
            ->orderByDesc('id')
            ->paginate(Base::getPaginate(100, 20));
        $lists = Base::getPageList($lists, false);
        //
        foreach ($lists['lists'] AS $key => $item) {
            $basic = Users::username2basic($item['username']);
            $item['nickname'] = $basic ? $basic['nickname'] : ($item['nickname'] || '');
            $item['userimg'] = $basic ? $basic['userimg'] : ($item['userimg'] || '');
            $item['message'] = Base::string2array($item['message']);
            $lists['lists'][$key] = $item;
        }
        //
        return Base::retSuccess('success', $lists);
    }
}
