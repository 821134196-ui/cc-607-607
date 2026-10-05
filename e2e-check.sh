#!/usr/bin/env bash
# 端到端业务规则验证（走真实 HTTP API）
set -u
BASE=http://127.0.0.1:8000/api
PASS=0; FAIL=0
ok()   { echo "  ✅ $1"; PASS=$((PASS+1)); }
bad()  { echo "  ❌ $1"; FAIL=$((FAIL+1)); }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (期望 $2, 实际 $1)"; fi; }

# GET  api_get <path> [staffId]
api_get() {
  local hdr=()
  [ -n "${2:-}" ] && hdr=(-H "X-Staff-User-Id: $2")
  curl -s -H 'Accept: application/json' "${hdr[@]}" "$BASE$1"
}
# POST api_post <path> <json> [staffId]  —— 输出: body换行http_code
api_post() {
  local hdr=()
  [ -n "${3:-}" ] && hdr=(-H "X-Staff-User-Id: $3")
  curl -s -w $'\n%{http_code}' -X POST \
    -H 'Accept: application/json' -H 'Content-Type: application/json' "${hdr[@]}" \
    "$BASE$1" -d "$2"
}
# 从 JSON 中取值：jget '<python表达式，d为根>'
jget() { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
split_code() { tail -1; }
split_body() { sed '$d'; }

echo "=== 准备：员工与场次 ==="
FD=$(api_get /staff | jget "[u['id'] for u in d['data'] if u['role']=='frontdesk'][0]")
TECH=$(api_get /staff | jget "[u['id'] for u in d['data'] if u['role']=='technician'][0]")
MGR=$(api_get /staff | jget "[u['id'] for u in d['data'] if u['role']=='manager'][0]")
S1=$(api_get /screenings | jget "sorted([s['id'] for s in d['data'] if s['state']=='scheduled'])[0]")
LIVE=$(api_get /screenings | jget "[s['id'] for s in d['data'] if s['state']=='live'][0]")
echo "  前台=$FD 技术员=$TECH 负责人=$MGR 未开演场=$S1 演出中场=$LIVE"

echo ""
echo "=== 规则1：改签释放旧占用 ==="
BEFORE=$(api_get "/screenings/$S1" | jget "[l for l in d['data']['languages'] if l['language']=='ja'][0]['remaining']")
echo "  场次$S1 日语余量(改签前): $BEFORE"

RES=$(api_post /reservations "{\"screening_id\":$S1,\"language\":\"ja\",\"audience_name\":\"E2E观众\",\"audience_phone\":\"13900000000\"}")
CODE=$(echo "$RES" | split_body | jget "d['data']['code']")
check "$(echo "$RES" | split_code)" "201" "日语预约成功（凭证 $CODE）"

AFTER_BOOK=$(api_get "/screenings/$S1" | jget "[l for l in d['data']['languages'] if l['language']=='ja'][0]['remaining']")
check "$AFTER_BOOK" "$((BEFORE-1))" "预约后余量减1（占用生效）"

RES2=$(api_post "/reservations/$CODE/reschedule" "{\"screening_id\":$S1,\"language\":\"zh\",\"audience_name\":\"E2E观众\"}")
NEWCODE=$(echo "$RES2" | split_body | jget "d['data']['new']['code']")
OLD_STATUS=$(echo "$RES2" | split_body | jget "d['data']['old']['status']")
check "$(echo "$RES2" | split_code)" "200" "改签请求成功"
check "$OLD_STATUS" "released" "改签后旧凭证 $CODE 状态为 released"

AFTER_RS=$(api_get "/screenings/$S1" | jget "[l for l in d['data']['languages'] if l['language']=='ja'][0]['remaining']")
check "$AFTER_RS" "$BEFORE" "改签后日语余量恢复（旧占用已释放）"
check "$(api_get "/reservations/$CODE" | jget "d['data']['status']")" "released" "旧凭证仍可追溯为 released"
check "$(api_get "/reservations/$NEWCODE" | jget "d['data']['status']")" "booked" "新凭证 $NEWCODE 为 booked 生效"

# 满员场景：把日语约满，再尝试改签进入
JA_REMAIN=$(api_get "/screenings/$S1" | jget "[l for l in d['data']['languages'] if l['language']=='ja'][0]['remaining']")
for i in $(seq 1 "$JA_REMAIN"); do
  api_post /reservations "{\"screening_id\":$S1,\"language\":\"ja\",\"audience_name\":\"占座$i\"}" >/dev/null
done
check "$(api_get "/screenings/$S1" | jget "[l for l in d['data']['languages'] if l['language']=='ja'][0]['remaining']")" "0" "日语已约满"

RS_FAIL=$(api_post "/reservations/$NEWCODE/reschedule" "{\"screening_id\":$S1,\"language\":\"ja\",\"audience_name\":\"E2E观众\"}")
check "$(echo "$RS_FAIL" | split_code)" "422" "改签到满员语言被拒（422）"
echo "$RS_FAIL" | split_body | grep -q '已约满' && ok "拒绝原因为约满" || bad "拒绝原因不对"
check "$(api_get "/reservations/$NEWCODE" | jget "d['data']['status']")" "booked" "改签失败后原预约仍 booked（事务回滚）"

echo ""
echo "=== 规则2：设备不会被重复分配 ==="
C3=$(api_post /reservations "{\"screening_id\":$S1,\"language\":\"zh\",\"audience_name\":\"领设备观众\"}" | split_body | jget "d['data']['code']")
LOOKUP=$(api_get "/frontdesk/reservations/$C3" "$FD")
D1=$(echo "$LOOKUP" | jget "d['data']['available_devices'][0]['id']")
D1SER=$(echo "$LOOKUP" | jget "d['data']['available_devices'][0]['serial']")
echo "  为 $C3 选择设备 $D1SER"

ISSUE=$(api_post "/frontdesk/reservations/$C3/issue" \
  "{\"device_id\":$D1,\"battery_percent\":88,\"physical_ok\":true,\"language_confirmed\":true}" "$FD")
check "$(echo "$ISSUE" | split_code)" "201" "首次发放成功"

C4=$(api_post /reservations "{\"screening_id\":$S1,\"language\":\"zh\",\"audience_name\":\"重复领设备观众\"}" | split_body | jget "d['data']['code']")
DUP=$(api_post "/frontdesk/reservations/$C4/issue" \
  "{\"device_id\":$D1,\"battery_percent\":80,\"physical_ok\":true,\"language_confirmed\":true}" "$FD")
check "$(echo "$DUP" | split_code)" "422" "同一设备重复发放被拒（422）"
echo "$DUP" | split_body | grep -qE '不能重复分配|尚未归还' && ok "拒绝原因：未归还/不能重复分配" || bad "拒绝原因不对"

NOCHECK=$(api_post "/frontdesk/reservations/$C4/issue" \
  "{\"device_id\":$D1,\"battery_percent\":80,\"physical_ok\":false,\"language_confirmed\":true}" "$FD")
check "$(echo "$NOCHECK" | split_code)" "422" "实物核对未通过不能发放"

D2=$(api_get "/frontdesk/reservations/$C4" "$FD" | jget "[x['id'] for x in d['data']['available_devices']][0]")
ISS2=$(api_post "/frontdesk/reservations/$C4/issue" \
  "{\"device_id\":$D2,\"battery_percent\":80,\"physical_ok\":true,\"language_confirmed\":true}" "$FD")
check "$(echo "$ISS2" | split_code)" "201" "发放第二台设备成功"
A2=$(echo "$ISS2" | split_body | jget "d['data']['assignment_id']")

RET=$(api_post /frontdesk/return \
  "{\"assignment_id\":$A2,\"battery_percent\":72,\"physical_ok\":true,\"language_confirmed\":true,\"faulty\":false}" "$FD")
check "$(echo "$RET" | split_code)" "200" "散场复检合格收回"
echo "$RET" | split_body | grep -q '复检通过' && ok "提示可继续使用" || true

# D1 未归还时给下一个预约 → 拒绝
C5=$(api_post /reservations "{\"screening_id\":$S1,\"language\":\"zh\",\"audience_name\":\"下一位观众\"}" | split_body | jget "d['data']['code']")
UNRET=$(api_post "/frontdesk/reservations/$C5/issue" \
  "{\"device_id\":$D1,\"battery_percent\":90,\"physical_ok\":true,\"language_confirmed\":true}" "$FD")
check "$(echo "$UNRET" | split_code)" "422" "未归还设备不能分配给下一位"

# 把 D1 收回但复检故障 → 锁定
A1=$(api_get "/frontdesk/outstanding?screening_id=$S1" "$FD" | jget "[a['assignment_id'] for a in d['data'] if a['reservation_code']=='$C3'][0]")
RETF=$(api_post /frontdesk/return \
  "{\"assignment_id\":$A1,\"battery_percent\":8,\"physical_ok\":false,\"language_confirmed\":true,\"faulty\":true,\"note\":\"屏幕不亮\"}" "$FD")
check "$(echo "$RETF" | split_code)" "200" "故障设备完成收回登记"
echo "$RETF" | split_body | grep -q '故障' && ok "提示设备锁定、不能分配给下一场" || true

C6=$(api_post /reservations "{\"screening_id\":$S1,\"language\":\"zh\",\"audience_name\":\"再下一位观众\"}" | split_body | jget "d['data']['code']")
FAULT=$(api_post "/frontdesk/reservations/$C6/issue" \
  "{\"device_id\":$D1,\"battery_percent\":90,\"physical_ok\":true,\"language_confirmed\":true}" "$FD")
check "$(echo "$FAULT" | split_code)" "422" "故障设备仍不能发放"

api_get /admin/devices "$FD" | python3 -c "
import json,sys
d=json.load(sys.stdin)['summary']
assert d['faulty']>=1 and d['assigned']==0
print('  ✅ 后台总览正确：总数 %(total)d，可发放 %(available)d，占用 %(assigned)d，故障 %(faulty)d' % d)
"

# 检查记录两阶段都有
api_get /admin/checks "$FD" | python3 -c "
import json,sys
phases={c['phase'] for c in json.load(sys.stdin)['data']}
assert {'issue','return'} <= phases
print('  ✅ 后台可查发放核对与散场复检两阶段检查结果')
"

echo ""
echo "=== 规则3：演出中换稿必须批准 + 历史可查 ==="
HIST0=$(api_get "/screenings/$LIVE/subtitles/zh")
CUR0=$(echo "$HIST0" | jget "d['data']['current_version_id']")
echo "  当前稿版本ID=$CUR0（演出中发布的新版本不得自动替换它）"

PUB=$(api_post "/screenings/$LIVE/subtitles" \
  '{"language":"zh","title":"E2E修订稿","content":"1\n00:00:01,000 --> 00:00:03,000\n修订后的台词\n"}' "$TECH")
check "$(echo "$PUB" | split_code)" "201" "技术员发布新版本成功"
echo "$PUB" | split_body | grep -q '必须经负责人批准' && ok "接口明确提示需负责人批准" || true
NEWV=$(echo "$PUB" | split_body | jget "d['data']['version_id']")
check "$(api_get "/screenings/$LIVE/subtitles/zh" | jget "d['data']['current_version_id']")" "$CUR0" "发布后当前稿未变"

SW=$(api_post "/screenings/$LIVE/subtitle-switch" \
  "{\"language\":\"zh\",\"to_version_id\":$NEWV,\"reason\":\"E2E验证换稿\"}" "$TECH")
check "$(echo "$SW" | split_code)" "201" "换稿申请已提交"
REQID=$(echo "$SW" | split_body | jget "d['data']['request_id']")

check "$(api_post "/subtitle-switch/$REQID/approve" '{}' "$FD" | split_code)" "403" "前台身份审批被拒（403）"
check "$(api_post "/subtitle-switch/$REQID/approve" '{}' "$TECH" | split_code)" "403" "技术员不能自行批准（403）"
check "$(api_get "/screenings/$LIVE/subtitles/zh" | jget "d['data']['current_version_id']")" "$CUR0" "等待批准期间现场仍用原稿"

APPR=$(api_post "/subtitle-switch/$REQID/approve" '{"decision_note":"E2E同意"}' "$MGR")
check "$(echo "$APPR" | split_code)" "200" "负责人批准成功"
echo "$APPR" | split_body | grep -q '切换' && ok "返回中记录实际切换时间" || true
check "$(api_get "/screenings/$LIVE/subtitles/zh" | jget "d['data']['current_version_id']")" "$NEWV" "批准后当前稿切换为新版本"

# 重复审批被拒
check "$(api_post "/subtitle-switch/$REQID/approve" '{}' "$MGR" | split_code)" "422" "已处理申请不能重复审批"

# 历史版本 + 旧文件可查
api_get "/screenings/$LIVE/subtitles/zh" | python3 -c "
import json,sys,urllib.request
d=json.load(sys.stdin)['data']
vers=d['versions']
assert len(vers)>=2 and sum(v['is_current'] for v in vers)==1
old=[v for v in vers if not v['is_current']][0]
raw=json.load(urllib.request.urlopen('$BASE/subtitle-versions/%d/content'%old['id']))
assert raw['data']['content']
print('  ✅ 版本历史完整（共 %d 版，一版当前），旧版 v%d 文件仍可查：%s' % (len(vers), old['version_no'], old['file_path']))
"
api_get "/screenings/$LIVE/subtitle-switch" "$MGR" | python3 -c "
import json,sys
r=[x for x in json.load(sys.stdin)['data'] if x['id']==$REQID][0]
assert r['status']=='approved' and r['switched_at'] and r['decided_by']
print('  ✅ 审批记录可查：%s v%s→v%s，切换于 %s，审批人 %s' % (r['status'], r['from_version']['version_no'], r['to_version']['version_no'], r['switched_at'], r['decided_by']))
"

# 驳回路径：再发一版，申请后驳回，当前稿不变
V3=$(api_post "/screenings/$LIVE/subtitles" '{"language":"zh","title":"E2E再修订","content":"再改\n"}' "$TECH" | split_body | jget "d['data']['version_id']")
REQ2=$(api_post "/screenings/$LIVE/subtitle-switch" "{\"language\":\"zh\",\"to_version_id\":$V3,\"reason\":\"测试驳回\"}" "$TECH" | split_body | jget "d['data']['request_id']")
check "$(api_post "/subtitle-switch/$REQ2/reject" '{"decision_note":"不同意"}' "$MGR" | split_code)" "200" "驳回流程成功"
check "$(api_get "/screenings/$LIVE/subtitles/zh" | jget "d['data']['current_version_id']")" "$NEWV" "驳回后继续使用原当前稿"

echo ""
echo "============================================================"
echo "  E2E 结果：$PASS 通过, $FAIL 失败"
echo "============================================================"
exit $FAIL
