.PHONY: fmt lint check black ruff
PYDIRS = scripts

fmt:
	black $(PYDIRS)

lint:
	ruff check $(PYDIRS)

check: fmt lint
black: fmt
ruff: lint
