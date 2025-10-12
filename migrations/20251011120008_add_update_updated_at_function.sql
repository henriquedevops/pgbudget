-- +goose Up
-- Create generic trigger function for updating updated_at timestamp
create or replace function utils.update_updated_at()
returns trigger as $$
begin
    new.updated_at = now();
    return new;
end;
$$ language plpgsql;

-- +goose Down
drop function if exists utils.update_updated_at();
