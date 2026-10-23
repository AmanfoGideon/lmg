-- Keep report aggregation inside Postgres so the browser only receives grouped rows.
create or replace function public.get_financial_report(
  p_group text,
  p_start date,
  p_end date,
  p_branch text default null
)
returns jsonb
language plpgsql
security invoker
set search_path = public, pg_temp
as $$
declare
  v_result jsonb;
begin
  if not public.is_active_member() then
    raise exception 'An active authenticated account is required' using errcode = '42501';
  end if;
  if p_group not in ('daily', 'weekly', 'monthly') or p_start is null or p_end is null or p_end < p_start then
    raise exception 'Invalid report range or grouping' using errcode = '22023';
  end if;

  with filtered as materialized (
    select t.date, t.type, t.amount,
      coalesce(nullif(btrim(t.payment_method), ''), 'unspecified') as payment_method
    from public.transactions as t
    where t.date between p_start and p_end
      and (p_branch is null or p_branch = '' or t.branch = p_branch)
  ), grouped as (
    select
      case p_group
        when 'weekly' then to_char(date, 'IYYY-"W"IW')
        when 'monthly' then to_char(date, 'YYYY-MM')
        else to_char(date, 'YYYY-MM-DD')
      end as period,
      coalesce(sum(case when type in ('income', 'payment') then amount else 0 end), 0) as income,
      coalesce(sum(case when type = 'expense' then amount else 0 end), 0) as expense,
      count(*) as transactions
    from filtered
    group by 1
  ), rows_json as (
    select coalesce(jsonb_agg(jsonb_build_object(
      'period', period, 'income', income, 'expense', expense,
      'profit', income - expense, 'count', transactions
    ) order by period), '[]'::jsonb) as rows
    from grouped
  ), totals as (
    select coalesce(sum(income), 0) as income, coalesce(sum(expense), 0) as expense,
      coalesce(sum(transactions), 0) as transactions
    from grouped
  ), method_rows as (
    select payment_method,
      count(*) filter (where type in ('income', 'payment')) as income_count,
      coalesce(sum(amount) filter (where type in ('income', 'payment')), 0) as income_total,
      count(*) filter (where type = 'expense') as expense_count,
      coalesce(sum(amount) filter (where type = 'expense'), 0) as expense_total
    from filtered
    where type in ('income', 'payment', 'expense')
    group by payment_method
  ), method_json as (
    select coalesce(jsonb_agg(jsonb_build_object(
      'payment_method', payment_method,
      'income_count', income_count,
      'income_total', income_total,
      'income_percent', case when totals.income = 0 then 0 else round(income_total * 100 / totals.income, 2) end,
      'expense_count', expense_count,
      'expense_total', expense_total,
      'expense_percent', case when totals.expense = 0 then 0 else round(expense_total * 100 / totals.expense, 2) end
    ) order by payment_method), '[]'::jsonb) as methods
    from method_rows cross join totals
  )
  select jsonb_build_object(
    'group', p_group, 'start', p_start, 'end', p_end,
    'rows', rows_json.rows,
    'totals', jsonb_build_object('income', totals.income, 'expense', totals.expense,
      'profit', totals.income - totals.expense, 'count', totals.transactions),
    'payment_methods', method_json.methods
  ) into v_result
  from rows_json cross join totals cross join method_json;
  return v_result;
end;
$$;

revoke all on function public.get_financial_report(text, date, date, text) from public, anon;
grant execute on function public.get_financial_report(text, date, date, text) to authenticated;

create or replace function public.get_report_branches()
returns text[]
language plpgsql
security invoker
set search_path = public, pg_temp
as $$
declare
  v_branches text[];
begin
  if not public.is_active_member() then
    raise exception 'An active authenticated account is required' using errcode = '42501';
  end if;
  select coalesce(array_agg(branch order by branch), array[]::text[])
    into v_branches
  from (select distinct branch from public.transactions where branch is not null and btrim(branch) <> '') as branches;
  return v_branches;
end;
$$;

revoke all on function public.get_report_branches() from public, anon;
grant execute on function public.get_report_branches() to authenticated;
