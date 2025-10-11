-- +goose Up
-- Fix delete_category_goal function type mismatch
-- v_deleted should be integer, not boolean, for GET DIAGNOSTICS row_count

-- +goose StatementBegin
create or replace function api.delete_category_goal(
    p_goal_uuid text
) returns boolean as $apifunc$
declare
    v_user_data text := utils.get_user();
    v_deleted integer;  -- Changed from boolean to integer
begin
    -- delete the goal (RLS ensures user ownership)
    delete from data.category_goals
    where uuid = p_goal_uuid
      and user_data = v_user_data;

    -- check if anything was deleted
    get diagnostics v_deleted = row_count;

    if v_deleted = 0 then
        raise exception 'Goal with UUID % not found for current user', p_goal_uuid;
    end if;

    return true;
end;
$apifunc$ language plpgsql volatile security invoker;
-- +goose StatementEnd

-- +goose StatementBegin
comment on function api.delete_category_goal(text) is 'Delete a goal';
-- +goose StatementEnd

-- +goose Down
-- Revert to original (broken) version

-- +goose StatementBegin
create or replace function api.delete_category_goal(
    p_goal_uuid text
) returns boolean as $apifunc$
declare
    v_user_data text := utils.get_user();
    v_deleted boolean;  -- Original incorrect type
begin
    -- delete the goal (RLS ensures user ownership)
    delete from data.category_goals
    where uuid = p_goal_uuid
      and user_data = v_user_data;

    -- check if anything was deleted
    get diagnostics v_deleted = row_count;

    if v_deleted = 0 then
        raise exception 'Goal with UUID % not found for current user', p_goal_uuid;
    end if;

    return true;
end;
$apifunc$ language plpgsql volatile security invoker;
-- +goose StatementEnd
