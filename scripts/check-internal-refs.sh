#!/usr/bin/env bash
# Refuse to publish internal-only references.
#
# This repository is public. Everything tracked here is readable by customers,
# competitors and crawlers, comments included. The patterns below are things
# that only exist inside the private monorepo: source layout, dev hostnames,
# ticket ids, agent scratch paths, and real API keys. A comment may explain
# WHAT the API does; it may not say WHERE our server code lives. A doc may show
# where a key goes; it may not contain one.
#
# Usage:  scripts/check-internal-refs.sh [--staged | --range BASE..HEAD]
#   (no args)           scan every tracked file    (what CI runs on a push)
#   --staged            scan the index instead     (what a pre-commit hook runs)
#   --range BASE..HEAD  scan the lines each commit of the range adds, so a
#                       leak removed by a later commit still fails (pull requests)
#
# Checks: every tracked text file against the pattern list below, plus a
# refusal of tracked executables, whose build paths no text search can see.
#
# An accepted exception goes in .internal-refs-allow, one extended regex per
# line, matched against "path:line:text" (or the bare path, for an executable).
# Comments and blank lines ignored.

set -uo pipefail

mode="${1:-all}"
allow_file=".internal-refs-allow"

# One pattern per class. Kept separate rather than alternated into a single
# regex so a hit names the class it violated.
patterns=(
  'apps/scrapfly/'                        # monorepo application paths
  'scrapfly-apps'                         # the private monorepo itself
  'scrapfly-api/pkg'                      # api internals
  'web-app/src/'                          # dashboard internals
  'scrape_engine/'                        # scrape engine internals
  '[a-z0-9-]+\.(scrapfly|web-scraping-dev)[a-z0-9.-]*\.?(home|local)\b'  # internal dev hostnames
  '(scrapfly|web-scraping-dev)[a-z0-9.-]*\.(home|local)\b'
  '/root/'                                # agent and developer machine paths
  '/home/[a-z]'
  '/opt/(go-sdk|mcp-oss)'                 # container-only replace targets
  '\.claude/projects/'                    # agent session and memory paths
  'TIC-[0-9A-Fa-f]{4}-[0-9]'              # support ticket ids
  '(SCRAPE-ENGINE|CLOUD-BROWSER|SCRAPIUM|WEB-APP|API)-[0-9][0-9A-Z]*'  # sentry issue ids
  'production-[0-9]{6}'                   # gcp project id
  'scrapfly-staff'                        # internal staff cli
  'scp-(live|test)-[0-9a-f]{32}'          # a real api key, not a placeholder
)

# A search that cannot run (a bad pattern, not a repository) fails the check:
# git grep and grep exit 1 for "no hit" and above 1 for an error.
added_lines=$(mktemp)
trap 'rm -f "$added_lines"' EXIT
case "$mode" in
  --staged) search() { git grep -nIE --cached "$1"; } ;;
  --range)
    range="${2:?--range takes BASE..HEAD}"
    # One "commit:path:+line" per added line of every commit in the range.
    git log --no-merges -p --unified=0 --format='commit %h' "$range" | awk '
      /^commit / { commit = $2; next }
      /^\+\+\+ b\// { path = substr($0, 7); next }
      /^\+\+\+ / { path = ""; next }
      /^\+/ && path != "" { print commit ":" path ":" $0 }
    ' > "$added_lines" || { echo "cannot read the commits of $range" >&2; exit 2; }
    search() { grep -E "$1" "$added_lines"; }
    ;;
  *) search() { git grep -nIE "$1"; } ;;
esac


# The allowlist is read once into a regex file; an empty one matches nothing.
allow_rules=$(mktemp)
trap 'rm -f "$allow_rules" "$added_lines"' EXIT
[ -f "$allow_file" ] && grep -vE '^[[:space:]]*(#|$)' "$allow_file" > "$allow_rules"

allowed() {
  [ -s "$allow_rules" ] || return 1
  printf '%s' "$1" | grep -qE -f "$allow_rules"
}

hits=0

# Tracked executables. `git grep` skips binaries entirely (-I), so no pattern
# below would ever see inside one — yet a compiled Go binary carries the
# absolute build paths of the tree it was built in and a vcs.revision from that
# tree's git history. Build output does not belong in a source repo anyway.
if ! command -v file >/dev/null 2>&1; then
  echo "the file command is missing: tracked executables cannot be checked" >&2
  exit 2
fi
if [ "$mode" != "--range" ]; then
  while IFS= read -r tracked; do
    [ -f "$tracked" ] || continue
    case "$(file -b --mime-type "$tracked" 2>/dev/null)" in
      application/x-executable|application/x-pie-executable|application/x-sharedlib|application/x-mach-binary|application/x-dosexec)
        allowed "$tracked" && continue
        printf 'tracked executable (leaks build paths and vcs.revision)\n  %s\n' "$tracked"
        hits=$((hits + 1))
        ;;
    esac
  done <<< "$(git ls-files)"
fi

results=$(mktemp)
trap 'rm -f "$allow_rules" "$added_lines" "$results"' EXIT
for pattern in "${patterns[@]}"; do
  search "$pattern" > "$results"
  status=$?
  if [ "$status" -gt 1 ]; then
    echo "search for [$pattern] failed (exit $status)" >&2
    exit 2
  fi
  while IFS= read -r hit; do
    [ -z "$hit" ] && continue
    # A range hit starts with its commit; the allowlist matches "path:line:text".
    location="$hit"
    [ "$mode" = "--range" ] && location="${hit#*:}"
    case "$location" in
      "$allow_file":*) continue ;;               # the allowlist quotes patterns by design
      scripts/check-internal-refs.sh:*) continue ;;  # so does this script
    esac
    allowed "$location" && continue
    printf 'internal reference [%s]\n  %s\n' "$pattern" "$hit"
    hits=$((hits + 1))
  done < "$results"
done

if [ "$hits" -gt 0 ]; then
  cat >&2 <<MSG

$hits internal exposure(s) would be published by this commit.

Rewrite the line to describe observable behaviour instead of our internal
location, drop the artifact, or add a justified exception to $allow_file.
MSG
  exit 1
fi
