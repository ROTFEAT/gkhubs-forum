.PHONY: cms-build cms-test cms-smoke

cms-build:
	docker build --target test -t gkhubs-cms:test cms
	docker build --target runtime -t gkhubs-cms:dev cms

cms-test: cms-build
	@echo "static checks passed in build stage"

cms-smoke: cms-build
	bash cms/tests/smoke.sh
