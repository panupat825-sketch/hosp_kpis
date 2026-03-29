-- Patch Set 3: rollback performance indexes

ALTER TABLE tb_strategies
  DROP INDEX idx_tb_strategies_mission;

ALTER TABLE tb_kpi_templates
  DROP INDEX idx_tb_kpi_templates_category,
  DROP INDEX idx_tb_kpi_templates_strategy,
  DROP INDEX idx_tb_kpi_templates_issue_mission,
  DROP INDEX idx_tb_kpi_templates_name;

ALTER TABLE tb_kpi_instances
  DROP INDEX idx_tb_kpi_instances_fy_tpl,
  DROP INDEX idx_tb_kpi_instances_tpl_fy_quarters,
  DROP INDEX idx_tb_kpi_instances_last_update;
