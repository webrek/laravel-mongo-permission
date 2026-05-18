<?php

namespace Webrek\MongoPermission;

class PermissionRegistrar
{
    protected ?string $teamId = null;
    protected bool $teamIdExplicitlySet = false;

    public function setTeamId(?string $teamId): self
    {
        $this->teamId = $teamId;
        $this->teamIdExplicitlySet = true;
        return $this;
    }

    public function getTeamId(): ?string
    {
        if ($this->teamIdExplicitlySet) {
            return $this->teamId;
        }

        $resolver = config('permission.team_resolver');
        if (is_callable($resolver)) {
            $resolved = $resolver();
            return $resolved === null ? null : (string) $resolved;
        }

        return null;
    }

    public function forgetTeamId(): self
    {
        $this->teamId = null;
        $this->teamIdExplicitlySet = false;
        return $this;
    }
}
