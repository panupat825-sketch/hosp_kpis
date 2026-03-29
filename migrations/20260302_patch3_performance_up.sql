-- Patch Set 3: performance indexes
-- Apply once, in a maintenance window if the tables are large.
-- Note: department_id/workgroup_id are stored as CSV in current tables, so they are not indexable in a useful way.

ALTER TABLE tb_kpi_instances
  ADD INDEX idx_tb_kpi_instances_fy_tpl (fiscal_year, template_id),
  ADD INDEX idx_tb_kpi_instances_tpl_fy_quarters (template_id, fiscal_year, quarter1, quarter2, quarter3, quarter4),
  ADD INDEX idx_tb_kpi_instances_last_update (last_update);

ALTER TABLE tb_kpi_templates
  ADD INDEX idx_tb_kpi_templates_category (category_id),
  ADD INDEX idx_tb_kpi_templates_strategy (strategy_id),
  ADD INDEX idx_tb_kpi_templates_issue_mission (strategic_issue(50), mission(50)),
  ADD INDEX idx_tb_kpi_templates_name (kpi_name(100));

ALTER TABLE tb_strategies
  ADD INDEX idx_tb_strategies_mission (mission_id);
