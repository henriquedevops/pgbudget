-- +goose Up
-- +goose StatementBegin

-- add onboarding tracking to users table
alter table data.users
add column if not exists onboarding_completed boolean default false,
add column if not exists onboarding_step integer default 0,
add column if not exists registered_at timestamptz default now();

-- budget templates table
create table if not exists data.budget_templates (
    id bigint generated always as identity primary key,
    uuid text not null default utils.nanoid(8),
    name text not null,
    description text,
    target_audience text, -- 'single', 'family', 'student', etc.
    categories jsonb not null, -- array of category groups and categories
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    
    constraint budget_templates_uuid_unique unique (uuid),
    constraint budget_templates_name_unique unique (name)
);

-- insert default templates
insert into data.budget_templates (name, description, target_audience, categories) values
('Single Person Starter', 'Basic categories for individual living', 'single',
 '{"groups": [
    {"name": "Food & Dining", "icon": "🍔", "categories": ["Groceries", "Restaurants"]},
    {"name": "Housing", "icon": "🏠", "categories": ["Rent", "Utilities", "Internet"]},
    {"name": "Transportation", "icon": "🚗", "categories": ["Gas", "Car Insurance", "Maintenance"]},
    {"name": "Bills & Subscriptions", "icon": "💳", "categories": ["Phone", "Streaming Services", "Gym"]},
    {"name": "Entertainment & Lifestyle", "icon": "🎬", "categories": ["Entertainment", "Clothing", "Personal Care"]},
    {"name": "Savings & Goals", "icon": "💰", "categories": ["Emergency Fund", "Future Goals"]}
  ]}'::jsonb),

('Family Budget', 'Categories for household management', 'family',
 '{"groups": [
    {"name": "Food & Dining", "icon": "🍔", "categories": ["Groceries", "Restaurants", "School Lunches"]},
    {"name": "Housing", "icon": "🏠", "categories": ["Mortgage/Rent", "Utilities", "Home Maintenance", "Property Tax"]},
    {"name": "Transportation", "icon": "🚗", "categories": ["Gas", "Car Payment", "Car Insurance", "Maintenance"]},
    {"name": "Family", "icon": "👨‍👩‍👧", "categories": ["Childcare", "Kids Activities", "School Supplies", "Clothing"]},
    {"name": "Healthcare", "icon": "🏥", "categories": ["Health Insurance", "Medical", "Prescriptions"]},
    {"name": "Entertainment", "icon": "🎬", "categories": ["Family Activities", "Subscriptions", "Hobbies"]},
    {"name": "Savings & Goals", "icon": "💰", "categories": ["Emergency Fund", "College Savings", "Vacation"]}
  ]}'::jsonb),

('Student Budget', 'Education-focused with limited income', 'student',
 '{"groups": [
    {"name": "Food & Dining", "icon": "🍔", "categories": ["Groceries", "Dining Out", "Coffee"]},
    {"name": "Housing", "icon": "🏠", "categories": ["Rent", "Utilities", "Internet"]},
    {"name": "Transportation", "icon": "🚗", "categories": ["Gas", "Public Transit", "Parking"]},
    {"name": "Education", "icon": "📚", "categories": ["Tuition", "Books", "Supplies", "Technology"]},
    {"name": "Personal", "icon": "👤", "categories": ["Phone", "Clothing", "Personal Care"]},
    {"name": "Entertainment", "icon": "🎬", "categories": ["Entertainment", "Subscriptions"]},
    {"name": "Savings", "icon": "💰", "categories": ["Emergency Fund", "Future Goals"]}
  ]}'::jsonb);

-- api view for budget templates
create or replace view api.budget_templates with (security_invoker = true) as
select
    uuid,
    name,
    description,
    target_audience,
    categories
from data.budget_templates;

-- grant select on templates view
grant select on api.budget_templates to pgb_web_user;

-- function to apply a budget template
create or replace function api.apply_budget_template(
    p_ledger_uuid text,
    p_template_uuid text
) returns jsonb as $$
declare
    v_ledger_id bigint;
    v_template record;
    v_group jsonb;
    v_category text;
    v_group_account_id bigint;
    v_category_account_id bigint;
    v_created_categories jsonb := '[]'::jsonb;
    v_user_data text := utils.get_user();
begin
    -- get ledger id
    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid
    and user_data = v_user_data;
    
    if v_ledger_id is null then
        raise exception 'Ledger not found or does not belong to current user';
    end if;
    
    -- get template
    select * into v_template
    from data.budget_templates
    where uuid = p_template_uuid;
    
    if v_template is null then
        raise exception 'Template not found';
    end if;
    
    -- iterate through groups
    for v_group in select * from jsonb_array_elements(v_template.categories->'groups')
    loop
        -- create category group
        insert into data.accounts (
            ledger_id,
            name,
            type,
            internal_type,
            is_group,
            metadata,
            user_data
        ) values (
            v_ledger_id,
            v_group->>'name',
            'equity',
            'liability_like',
            true,
            jsonb_build_object('icon', v_group->>'icon'),
            v_user_data
        ) returning id into v_group_account_id;
        
        -- create categories in this group
        for v_category in select * from jsonb_array_elements_text(v_group->'categories')
        loop
            insert into data.accounts (
                ledger_id,
                name,
                type,
                internal_type,
                parent_category_id,
                user_data
            ) values (
                v_ledger_id,
                v_category,
                'equity',
                'liability_like',
                v_group_account_id,
                v_user_data
            ) returning id into v_category_account_id;
            
            -- add to created categories list
            v_created_categories := v_created_categories || jsonb_build_object(
                'group', v_group->>'name',
                'category', v_category
            );
        end loop;
    end loop;
    
    return jsonb_build_object(
        'success', true,
        'categories_created', jsonb_array_length(v_created_categories),
        'categories', v_created_categories
    );
end;
$$ language plpgsql volatile security definer;

-- grant execute on template function
grant execute on function api.apply_budget_template(text, text) to pgb_web_user;

-- function to complete onboarding step
create or replace function api.complete_onboarding_step(
    p_step integer
) returns jsonb as $$
declare
    v_user_data text := utils.get_user();
    v_current_step integer;
begin
    -- get current step
    select onboarding_step into v_current_step
    from data.users
    where user_data = v_user_data;
    
    -- update to new step if it's the next step
    if p_step = v_current_step + 1 then
        update data.users
        set onboarding_step = p_step,
            onboarding_completed = (p_step >= 5)
        where user_data = v_user_data;
        
        return jsonb_build_object(
            'success', true,
            'current_step', p_step,
            'completed', p_step >= 5
        );
    else
        return jsonb_build_object(
            'success', false,
            'error', 'Invalid step progression',
            'current_step', v_current_step
        );
    end if;
end;
$$ language plpgsql volatile security definer;

-- grant execute on onboarding function
grant execute on function api.complete_onboarding_step(integer) to pgb_web_user;

-- function to skip onboarding
create or replace function api.skip_onboarding() returns jsonb as $$
declare
    v_user_data text := utils.get_user();
begin
    update data.users
    set onboarding_completed = true,
        onboarding_step = 5
    where user_data = v_user_data;
    
    return jsonb_build_object('success', true, 'skipped', true);
end;
$$ language plpgsql volatile security definer;

-- grant execute on skip function
grant execute on function api.skip_onboarding() to pgb_web_user;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- drop functions
drop function if exists api.skip_onboarding();
drop function if exists api.complete_onboarding_step(integer);
drop function if exists api.apply_budget_template(text, text);

-- drop view
drop view if exists api.budget_templates;

-- drop table
drop table if exists data.budget_templates;

-- remove columns from users table
alter table data.users
drop column if exists onboarding_completed,
drop column if exists onboarding_step,
drop column if exists registered_at;

-- +goose StatementEnd
