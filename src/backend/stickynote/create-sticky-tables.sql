create table note
	(ordinal int primary key, login text, domain text, 
	inserted timestamp with time zone default now(), 
	modified timestamp with time zone default now(),
	deleted boolean default false, subject text, content text, 
	categories text);

create table categories
	(ordinal int references note(ordinal) on update cascade 
	on delete cascade, tag text);

create index note_login on note using btree(login, domain);
create index tag_ordinal on categories using btree(ordinal);
create sequence ordinal;
grant all on note, categories, ordinal to stickynote;
