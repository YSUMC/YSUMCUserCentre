import { Adapter, AdapterPayload, AdapterFactory } from 'oidc-provider';
import { ExtendedPrismaClient } from './extended-prisma-client';
import { ConfigService } from '@nestjs/config';

export type ModelType =
    "Grant" |
    "Session" |
    "AccessToken" |
    "AuthorizationCode" |
    "RefreshToken" |
    "ClientCredentials" |
    "Client" |
    "InitialAccessToken" |
    "RegistrationAccessToken" |
    "DeviceCode" |
    "Interaction" |
    "ReplayDetection" |
    "BackchannelAuthenticationRequest" |
    "PushedAuthorizationRequest";

export class OIDCAdapter implements Adapter {

    private readonly model: string;

    constructor(private readonly type: ModelType, private readonly prisma: ExtendedPrismaClient, private readonly config: ConfigService) {
        this.model = type.charAt(0).toLowerCase() + type.slice(1);
    }

    public static getAdapterFactory(prisma: ExtendedPrismaClient, config: ConfigService): AdapterFactory {
        return (type: ModelType) => {
            return new OIDCAdapter(type, prisma, config);
        };
    }

    async upsert(id: string, payload: AdapterPayload): Promise<any> {
        const data = {
            uid: payload.uid,
            payload: payload,
            userCode: payload.userCode
        };

        await this.prisma[this.model].upsert({
            where: {
                id: id
            },
            create: {
                id: id,
                ...data
            },
            update: {
                ...data
            }
        });
    }

    async find(id: string): Promise<AdapterPayload | undefined> {

        const data = await this.prisma[this.model].findFirst({
            where: {
                id: this.type == 'Client' ? parseInt(id) : id
            }
        });

        if (!data) {
            return undefined;
        }

        // Blessing Skin revokes tokens in Passport's tables. Janus must honor
        // that state before accepting its separate refresh-token payload.
        if (this.type === 'RefreshToken') {
            const refreshToken = await this.prisma.passportRefreshToken.findUnique({
                where: { id },
            });
            if (!refreshToken || refreshToken.revoked) {
                return undefined;
            }
            const accessToken = await this.prisma.passportAccessToken.findUnique({
                where: { id: refreshToken.access_token_id },
            });
            if (!accessToken || accessToken.revoked) {
                return undefined;
            }
        }

        const accountId = data.payload?.accountId;
        if (accountId) {
            const user = await this.prisma.user.findFirst({
                where: {
                    uid: parseInt(accountId)
                }
            });

            if (!user) {
                return undefined;
            }
        }

        return this.restorePayload(data);
    }

    async findByUserCode(userCode: string): Promise<AdapterPayload | undefined> {
        const data = await this.prisma.deviceCode.findFirst({
            where: {
                userCode: userCode
            }
        });

        if (data) {
            return this.restorePayload(data);
        }
    }

    async findByUid(uid: string): Promise<AdapterPayload | undefined> {
        const data = await this.prisma[this.model].findFirst({
            where: {
                uid: uid
            }
        });

        if (!data) {
            return undefined;
        }

        return this.restorePayload(data);
    }

    private restorePayload(data: {
        payload: unknown;
        consumed?: boolean;
        updated_at?: Date;
    }): AdapterPayload {
        const payload = data.payload as AdapterPayload;
        if (!data.consumed) {
            return payload;
        }
        // oidc-provider uses this timestamp to reject replayed one-use tokens.
        return {
            ...payload,
            consumed: Math.floor((data.updated_at?.getTime() ?? Date.now()) / 1000),
        };
    }

    async consume(id: string): Promise<void> {
        await this.prisma[this.model].update({
            where: {
                id: id
            },
            data: {
                consumed: true
            }
        });
    }

    async destroy(id: string): Promise<void> {
        await this.prisma[this.model].deleteMany({
            where: {
                id: id
            }
        });
    }

    async revokeByGrantId(grantId: string): Promise<undefined | void> {
        await this.prisma.grant.deleteMany({
            where: {
                id: grantId
            }
        });
    }
}
