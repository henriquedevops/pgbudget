-- +goose Up
-- +goose StatementBegin

-- function to skip onboarding
create or replace function api.skip_onboarding() returns jsonb as $$
declare
    v_username text := utils.get_user();
begin
    update data.users
    set onboarding_completed = true,
        onboarding_step = 5
    where username = v_username;

    return jsonb_build_object('success', true, 'skipped', true);
end;
$$ language plpgsql volatile security definer;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

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

-- +goose StatementEnd
