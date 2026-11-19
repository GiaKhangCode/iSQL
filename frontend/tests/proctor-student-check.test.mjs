import assert from "node:assert/strict";
import { build } from "esbuild";
import { rmSync } from "node:fs";

// Chuyển phản hồi của POST /api/proctor/sessions/{id}/students/check sang kiểu thẻ kết quả của ProctorPage.
const outfile = "tests/.proctor-student-check-bundle.mjs";
await build({
  entryPoints: ["src/shared/lib/proctor/studentCheck.ts"],
  bundle: true,
  format: "esm",
  platform: "node",
  outfile,
});

try {
  const { toStudentCheckResult } = await import(`../${outfile}?${Date.now()}`);
  const base = { studentId: "u1", studentCode: "21520001", name: "An", email: "an@gm.uit.edu.vn", message: "Thông báo" };

  const eligible = toStudentCheckResult({ ...base, status: "eligible", canAdd: true });
  assert.equal(eligible.ok, true);
  assert.equal(eligible.found, true);
  assert.equal(eligible.eligible, true);
  assert.equal(eligible.canTransfer, false);
  assert.equal(eligible.reason, "Thông báo");
  assert.deepEqual(eligible.student, { studentId: "u1", studentCode: "21520001", name: "An", email: "an@gm.uit.edu.vn" });
  assert.equal(eligible.previousSession, undefined);

  const inSession = toStudentCheckResult({ ...base, status: "already_in_session", canAdd: false });
  assert.equal(inSession.eligible, false);
  assert.equal(inSession.canTransfer, false);
  assert.equal(inSession.previousSession, undefined);
  assert.equal(inSession.student.name, "An");

  const transfer = toStudentCheckResult({ ...base, status: "in_other_session", canAdd: true, otherSessionId: "s2", otherSession: "Ca 2" });
  assert.equal(transfer.eligible, true);
  assert.equal(transfer.canTransfer, true);
  assert.deepEqual(transfer.previousSession, { id: "s2", title: "Ca 2", status: "absent" });

  const participated = toStudentCheckResult({ ...base, status: "already_participated", canAdd: false, otherSessionId: "s2", otherSession: "Ca 2" });
  assert.equal(participated.eligible, false);
  assert.equal(participated.canTransfer, false);
  assert.equal(participated.previousSession.status, "started");

  // Thuộc ca khác qua lớp: không chuyển riêng lẻ được, nhưng vẫn hiện ca đó.
  const viaClass = toStudentCheckResult({ ...base, status: "in_other_session_class", canAdd: false, otherSessionId: "s2", otherSession: "Ca 2" });
  assert.equal(viaClass.eligible, false);
  assert.equal(viaClass.canTransfer, false);
  assert.equal(viaClass.previousSession.title, "Ca 2");
  assert.equal(viaClass.previousSession.status, "absent");

  console.log("proctor-student-check: ok");
} finally {
  rmSync(outfile, { force: true });
}
