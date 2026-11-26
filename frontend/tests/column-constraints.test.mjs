import assert from "node:assert/strict";
import { build } from "esbuild";
import { writeFile, unlink } from "node:fs/promises";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { join } from "node:path";

const bundle = await build({
  stdin: { contents: `
    export { parseDatabaseSchema } from "./src/shared/lib/sql/parseDatabaseSchema";
    export { columnTraits, constraintTags } from "./src/shared/lib/sql/columnConstraints";
  `, resolveDir: process.cwd(), loader: "ts" },
  bundle: true, write: false, platform: "node", format: "cjs",
});
const filename = join(tmpdir(), `tmp-constraints-${process.pid}.cjs`);
await writeFile(filename, bundle.outputFiles[0].contents);
try {
  const { parseDatabaseSchema, columnTraits, constraintTags } = createRequire(import.meta.url)(filename);
  const tables = parseDatabaseSchema(`
    CREATE TABLE KhachHang (
      MaKH INT PRIMARY KEY,
      Email VARCHAR(50) NOT NULL UNIQUE,
      HoTen NVARCHAR(100) NOT NULL,
      TrangThai VARCHAR(10) NOT NULL DEFAULT 'Active',
      Diem INT DEFAULT 0,
      CCCD VARCHAR(12),
      Sdt VARCHAR(12),
      Ma1 INT, Ma2 INT,
      CONSTRAINT uq_sdt UNIQUE (Sdt),
      CONSTRAINT uq_ma UNIQUE (Ma1, Ma2)
    );
    ALTER TABLE KhachHang ADD CONSTRAINT uq_cccd UNIQUE (CCCD);
    ALTER TABLE KhachHang ADD CONSTRAINT df_ngay DEFAULT GETDATE() FOR HoTen;
    ALTER TABLE KhachHang ALTER COLUMN Sdt VARCHAR(12) NOT NULL;
  `);
  const text = (name) => {
    const table = tables[0];
    return constraintTags(columnTraits(table, table.columns.find((c) => c.name === name))).map((t) => t.text);
  };
  assert.deepEqual(text("MaKH"), []);
  assert.deepEqual(text("Email"), ["NOT NULL", "UNIQUE"]);
  assert.deepEqual(text("HoTen"), ["NOT NULL", "DEFAULT GETDATE()"]);
  assert.deepEqual(text("TrangThai"), ["NOT NULL", "DEFAULT 'Active'"]);
  assert.deepEqual(text("Diem"), ["DEFAULT 0"]);
  assert.deepEqual(text("CCCD"), ["UNIQUE"]);
  assert.deepEqual(text("Sdt"), ["NOT NULL", "UNIQUE"]);
  assert.deepEqual(text("Ma1"), ["UNIQUE (Ma1, Ma2)"]);

  // Dữ liệu "CSDL của bạn" do backend đọc từ SQL Server: NOT NULL/NULL ở cột, UNIQUE và DEFAULT ở mức bảng.
  const current = {
    name: "T", primaryKeys: ["Id"], foreignKeys: [],
    columns: [
      { name: "Id", type: "int", constraints: "NOT NULL" },
      { name: "Ten", type: "varchar(20)", constraints: "NOT NULL" },
      { name: "Gia", type: "int", constraints: "NULL" },
      { name: "Ma", type: "varchar(5)", constraints: "NULL" },
    ],
    constraints: ["UNIQUE ([Ma])|disabled:0", "DEFAULT [Gia] = ((0))", "DEFAULT [Ten] = ('abc')"],
  };
  const of = (name) => constraintTags(columnTraits(current, current.columns.find((c) => c.name === name))).map((t) => t.text);
  assert.deepEqual(of("Id"), []);
  assert.deepEqual(of("Ten"), ["NOT NULL", "DEFAULT 'abc'"]);
  assert.deepEqual(of("Gia"), ["DEFAULT 0"]);
  assert.deepEqual(of("Ma"), ["UNIQUE"]);
  console.log("constraints OK");
} finally {
  await unlink(filename).catch(() => {});
}
