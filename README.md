# Asset Merge (AssetMerge)

GLPI 12 资产合并插件（非官方）。解决手工录入资产与 Agent 自动采集资产并存导致的重复记录问题。

> **状态**：0.2.0 — 骨架 + 只读预演 + 执行引擎已在测试环境验证通过。上生产前须按实施方案评审。

## 功能

- **只读预演**：在资产列表勾选多条记录后，通过 Massive Action 触发合并预演，逐字段生成差异报告（字段级冲突标注人工裁决）。
- **执行引擎**：事务化合并，全量迁移关联数据（工单 / 软件 / 磁盘 / 文档 / 合同 / 财务信息），源记录软删除（`is_deleted=1`，不物理删除），全程写审计日志。
- **字段锁**：搬移人工字段时加字段锁，防止 Agent 再次回写覆盖。
- **财务信息字段级合并**：源与目标互补字段合并，双方均有值且不同时记入冲突报告，保留目标值。
- **哨兵值处理**：`otherserial = 'No Asset Information'` 视为"无信息"占位，可被真实值直接覆盖，不判为冲突。

## 设计约束

1. 纯插件扩展，不修改 GLPI 核心代码（通过 `use_massive_actions`、hook、独立数据表实现）。
2. 许可证 GPLv2+，与 GLPI 及本项目其他插件一致。
3. 动态采集记录为合并保留方，静态手工记录为被合并方。
4. 审计留痕：日志只记录 ID、字段名、变更时间，不落全量使用人姓名 / IP。

## 环境要求

| 项目 | 要求 |
|---|---|
| GLPI | 12.0.x |
| PHP | 与 GLPI 12.0 要求一致 |

## 安装

将插件目录放置为 `<GLPI_ROOT>/plugins/assetmerge`（**目录名必须小写**，大写会被 `plugin:install` 判为无效），然后在 GLPI 中安装并启用。

```bash
cd /var/www/html/glpi/plugins
# 确认目录名为小写 assetmerge
sudo -u www-data php bin/console plugin:install assetmerge
sudo -u www-data php bin/console plugin:activate assetmerge
```

> 注意：GLPI 11+ 的 console 命令是 `plugin:install` / `plugin:activate`（无 `glpi:` 前缀），且必须以 Web 服务用户（如 `www-data`）身份执行。

## 用法

1. 进入资产列表（如"计算机"），勾选要合并的多条资产。
2. 批量操作选择 **Asset Merge → 预演合并**，查看字段差异与冲突报告。
3. 确认无误后选择 **执行合并**，结果写入审计日志。

详细风险评估与实施约束见 [`合规与风险评估及实施方案.md`](合规与风险评估及实施方案.md)。

## 许可证

GPL-2.0-or-later。详见 [LICENSE](LICENSE)。
