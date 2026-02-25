-- +goose Up
create or replace view api.past_installments as
select
    ip.uuid as plan_uuid,
    l.uuid  as ledger_uuid,
    ip.description || ' ' || s.installment_number::text
        || '/' || ip.number_of_installments::text  as description,
    date_trunc('month', s.due_date)::date           as month,
    -(s.scheduled_amount)::bigint                   as amount
from data.installment_schedules s
join data.installment_plans ip on ip.id = s.installment_plan_id
join data.ledgers l on l.id = ip.ledger_id
where s.status = 'processed'
  and ip.user_data = utils.get_user();

grant select on api.past_installments to pgbudget_user;

-- +goose Down
revoke select on api.past_installments from pgbudget_user;
drop view if exists api.past_installments;
